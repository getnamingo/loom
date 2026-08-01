<?php
/**
 * Argora Foundry
 *
 * A modular PHP boilerplate for building SaaS applications, admin panels, and control systems.
 *
 * @package    App
 * @author     Taras Kondratyuk <help@argora.org>
 * @copyright  Copyright (c) 2025 Argora
 * @license    MIT License
 * @link       https://github.com/getargora/foundry
 */

namespace App\Controllers;

use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;
use App\Auth\Auth;
use League\ISO3166\ISO3166;
use Respect\Validation\Validator as v;

class ProfileController extends Controller
{
    private $webAuthn;

    public function __construct(ContainerInterface $container) {
        parent::__construct($container);

        $this->webAuthn = null;
        if (envi('WEB_AUTHN_ENABLED') === 'true') {
            $this->webAuthn = new \lbuchs\WebAuthn\WebAuthn('Loom', envi('APP_DOMAIN'));
        }
    }

    public function profile(Request $request, Response $response)
    {
        $session = $_SESSION;
        $db = $this->container->get('db');

        $userId = $session['auth_user_id'];
        $email = $session['auth_email'];
        $username = $session['auth_username'];

        // Determine role
        $roleMap = [0 => 'Administrator', 4 => 'Client'];
        $role = $roleMap[$session['auth_roles']] ?? 'Unknown';

        // Determine status
        $status = ($session['auth_status'] == 0) ? 'Confirmed' : 'Unknown';
        
        // 2FA Setup
        $tfa = new TwoFactorAuth(
            issuer: "Foundry",
            qrcodeprovider: new BaconQRCodeProvider(0, '#ffffff', '#000000', 'svg')
        );
        $secret = $tfa->createSecret(160, true);
        $_SESSION['2fa_secret'] = $secret;
        $qrcodeDataUri = $tfa->getQRCodeImageAsDataUri($email, $secret);
            
        // CSRF Tokens
        $csrf = $this->container->get('csrf');
        $csrfName = $csrf->getTokenName();
        $csrfValue = $csrf->getTokenValue();

        // Fetch account data
        $is2FA = $db->selectValue('SELECT tfa_enabled FROM users WHERE id = ?', [$userId]);
        $webauthn = $db->select('SELECT * FROM users_webauthn WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [$userId]);
        $isWebAuthnEnabled = (envi('WEB_AUTHN_ENABLED') === 'true');

        $user_data = $db->selectRow('SELECT nin, vat_number, nin_type, validation, currency, account_balance FROM users WHERE id = ?', [$userId]);

        // Base payload
        $data = compact(
            'email', 'username', 'status', 'role',
            'csrfName', 'csrfValue', 'user_data'
        );
        $data['csrf_name'] = $csrfName;
        $data['csrf_value'] = $csrfValue;

        // Add security options to payload
        if ($is2FA) {
            // No QR code shown
            $data['isWebaEnabled'] = false;
        } elseif ($webauthn) {
            $data['qrcodeDataUri'] = $qrcodeDataUri;
            $data['secret'] = $secret;
            $data['weba'] = $webauthn;
            $data['isWebaEnabled'] = $isWebAuthnEnabled;
        } else {
            $data['qrcodeDataUri'] = $qrcodeDataUri;
            $data['secret'] = $secret;
            $data['isWebaEnabled'] = $isWebAuthnEnabled;
        }

        $contacts = $db->select("SELECT * FROM users_contact WHERE user_id = ?", [ $userId ]);
        if ($contacts) {
            $data['contacts'] = $contacts;

            $iso3166 = new ISO3166();
            $countries = $iso3166->all();
            $data['countries'] = $countries;
        }

        return view($response, 'admin/profile/profile.twig', $data);
    }

    public function activate2fa(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $verificationCode = $data['verificationCode'] ?? null;
            $userId = $_SESSION['auth_user_id'];
            $secret = $_SESSION['2fa_secret'];

            $csrfName = $this->container->get('csrf')->getTokenName();
            $csrfValue = $this->container->get('csrf')->getTokenValue();
            $username = $_SESSION['auth_username'];
            $email = $_SESSION['auth_email'];
            $status = $_SESSION['auth_status'];

            if ($status == 0) {
                $status = "Confirmed";
            } else {
                $status = "Unknown";
            }
            $roles = $_SESSION['auth_roles'];
            if ($roles == 0) {
                $role = "Admin";
            } else {
                $role = "Unknown";
            }
            
            try {
                $db->beginTransaction();
                $currentDateTime = new \DateTime();
                $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
                $db->insert(
                    'users_audit',
                    [
                        'user_id' => $_SESSION['auth_user_id'],
                        'user_event' => 'user.enable.2fa',
                        'user_resource' => 'control.panel',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                        'user_ip' => get_client_ip(),
                        'user_location' => get_client_location(),
                        'event_time' => $currentDate,
                        'user_data' => null
                    ]
                );
                $db->update(
                    'users',
                    [
                        'tfa_secret' => $secret,
                        'tfa_enabled' => 1,
                        'auth_method' => '2fa',
                        'backup_codes' => null
                    ],
                    [
                        'id' => $userId
                    ]
                );
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                return $response->withHeader('Location', '/profile')->withStatus(302);
            }
            $this->container->get('flash')->addMessage('success', '2FA for your user has been activated successfully');
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }
    }

    public function getRegistrationChallenge(Request $request, Response $response)
    {
        global $container;

        if ($this->webAuthn === null) {
            $response->getBody()->write(json_encode(['success' => false, 'msg' => 'WebAuthn is disabled.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $userName = $_SESSION['auth_username'];
        $userEmail = $_SESSION['auth_email'];
        $userId = $_SESSION['auth_user_id'];
        $registrations = $container->get('db')->select(
            'SELECT credential_id FROM users_webauthn WHERE user_id = ?',
            [$userId]
        ) ?: [];
        $credentialIds = [];
        foreach ($registrations as $registration) {
            $credentialId = base64_decode($registration['credential_id'], true);
            if ($credentialId !== false) {
                $credentialIds[] = $credentialId;
            }
        }

        $hexUserId = dechex($userId);
        // Ensure even length for the hexadecimal string
        if(strlen($hexUserId) % 2 != 0){
            $hexUserId = '0' . $hexUserId;
        }
        $createArgs = $this->webAuthn->getCreateArgs(\hex2bin($hexUserId), $userEmail, $userName, 60*5, 'preferred', 'required', null, $credentialIds);

        $response->getBody()->write(json_encode($createArgs));
        $_SESSION['webauthn_registration'] = [
            'challenge' => ($this->webAuthn->getChallenge())->getBinaryString(),
            'user_id' => $userId,
            'expires_at' => time() + 300
        ];
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function verifyRegistration(Request $request, Response $response)
    {
        global $container;

        try {
            if ($this->webAuthn === null) {
                throw new \RuntimeException('WebAuthn is disabled.');
            }

            $ceremony = $_SESSION['webauthn_registration'] ?? null;
            unset($_SESSION['webauthn_registration']);

            $userName = $_SESSION['auth_username'];
            $userEmail = $_SESSION['auth_email'];
            $userId = $_SESSION['auth_user_id'];
            if (
                !is_array($ceremony)
                || (int) ($ceremony['user_id'] ?? 0) !== (int) $userId
                || (int) ($ceremony['expires_at'] ?? 0) < time()
            ) {
                throw new \RuntimeException('WebAuthn registration challenge is invalid or expired.');
            }

            $data = json_decode($request->getBody()->getContents(), null, 512, JSON_THROW_ON_ERROR);

            // Decode the incoming data
            $clientDataJSON = validateWebAuthnClientData((string) ($data->clientDataJSON ?? ''));
            $attestationObject = base64_decode((string) ($data->attestationObject ?? ''), true);
            if ($attestationObject === false) {
                throw new \InvalidArgumentException('Invalid WebAuthn attestation data.');
            }

            // Retrieve the challenge from the session
            $challenge = $ceremony['challenge'];

            // Process the WebAuthn response
            $credential = $this->webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge, true, true, false);
            if (strlen($credential->credentialId) > 1023) {
                throw new \RuntimeException('WebAuthn credential ID is too long.');
            }

            // add user infos
            $credential->userId = $userId;
            $credential->userName = $userEmail;
            $credential->userDisplayName = $userName;

            // Store the credential data in the database
            $db = $container->get('db');
            $counter = is_null($credential->signatureCounter) ? 0 : $credential->signatureCounter;
            $credentialId = base64_encode($credential->credentialId);
            if ($db->selectValue('SELECT id FROM users_webauthn WHERE credential_id = ? LIMIT 1', [$credentialId])) {
                throw new \RuntimeException('This WebAuthn credential is already registered.');
            }

            $db->beginTransaction();
            try {
                $db->insert(
                    'users_webauthn',
                    [
                        'user_id' => $userId,
                        'credential_id' => $credentialId,
                        'public_key' => $credential->credentialPublicKey,
                        'attestation_object' => base64_encode($attestationObject),
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                        'sign_count' => $counter
                    ]
                );
                $db->update(
                    'users',
                    [
                        'auth_method' => 'webauthn'
                    ],
                    [
                        'id' => $userId
                    ]
                );
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            $msg = 'Registration success.';
            if ($credential->rootValid === false) {
                $msg = 'Registration ok, but certificate does not match any of the selected root ca.';
            }

            $return = new \stdClass();
            $return->success = true;
            $return->msg = $msg;

            // Send success response
            $response->getBody()->write(json_encode($return));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            // Handle error, return an appropriate response
            $response->getBody()->write(json_encode(['success' => false, 'msg' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }

    public function logoutEverywhereElse(Request $request, Response $response)
    {
        $db = $this->container->get('db');
        
        $currentDateTime = new \DateTime();
        $currentDate = $currentDateTime->format('Y-m-d H:i:s.v'); // Current timestamp
        $db->insert(
            'users_audit',
            [
                'user_id' => $_SESSION['auth_user_id'],
                'user_event' => 'user.logout.everywhere',
                'user_resource' => 'control.panel',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'user_ip' => get_client_ip(),
                'user_location' => get_client_location(),
                'event_time' => $currentDate,
                'user_data' => json_encode([
                    'remaining_session_id' => session_id(),
                    'logged_out_sessions' => 'All other sessions terminated',
                    'previous_ip' => $_SESSION['previous_ip'] ?? null,
                    'previous_user_agent' => $_SESSION['previous_user_agent'] ?? null,
                    'timestamp' => $currentDate,
                ])
            ]
        );

        Auth::logoutEverywhereElse();
    }

    public function tokenWell(Request $request, Response $response)
    {
        $csrf = $this->container->get('csrf');

        // Get CSRF token name and value
        $csrfTokenName = $csrf->getTokenName();
        $csrfTokenValue = $csrf->getTokenValue();

        // Check if tokens exist
        if (!$csrfTokenName || !$csrfTokenValue) {
            $errorResponse = json_encode(['error' => 'CSRF tokens not found']);
            $response->getBody()->write($errorResponse);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Create JSON response in the expected format
        $csrfResponse = json_encode([
            $csrfTokenName => $csrfTokenValue
        ]);

        // Write response body and return with JSON header
        $response->getBody()->write($csrfResponse);
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    public function updateContacts(Request $request, Response $response)
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $userId = $_SESSION['auth_user_id'];
            $username = $_SESSION['auth_username'];

            $data['owner']['cc'] = strtoupper($data['owner']['cc']);
            $data['billing']['cc'] = strtoupper($data['billing']['cc']);
            $data['tech']['cc'] = strtoupper($data['tech']['cc']);
            $data['abuse']['cc'] = strtoupper($data['abuse']['cc']);

            $phoneValidator = v::regex('/^\+\d{1,3}\.\d{2,12}$/');

            // Define validation for nested fields
            $contactValidator = [
                v::key('first_name', v::stringType()->notEmpty()->length(1, 255), true),
                v::key('last_name', v::stringType()->notEmpty()->length(1, 255), true),
                v::key('org', v::optional(v::stringType()->length(1, 255)), false),
                v::key('street1', v::optional(v::stringType()), false),
                v::key('city', v::stringType()->notEmpty(), true),
                v::key('sp', v::optional(v::stringType()), false),
                v::key('pc', v::optional(v::stringType()), false),
                v::key('cc', v::countryCode(), true),
                v::key('voice', v::optional($phoneValidator), false),
                v::key('fax', v::optional(v::phone()), false),
                v::key('email', v::email(), true)
            ];
            
            $validators = [
                'owner' => v::optional(v::keySet(...$contactValidator)),
                'billing' => v::optional(v::keySet(...$contactValidator)),
                'tech' => v::optional(v::keySet(...$contactValidator)),
                'abuse' => v::optional(v::keySet(...$contactValidator))
            ];

            $errors = [];
            foreach ($validators as $field => $validator) {
                try {
                    $validator->assert(isset($data[$field]) ? $data[$field] : []);
                } catch (\Respect\Validation\Exceptions\NestedValidationException $e) {
                    $errors[$field] = $e->getMessages();
                }
            }

            if (!empty($errors)) {
                // Handle errors
                $errorText = '';

                foreach ($errors as $field => $messages) {
                    $errorText .= ucfirst($field) . ' errors: ' . implode(', ', $messages) . '; ';
                }

                // Trim the final semicolon and space
                $errorText = rtrim($errorText, '; ');
                
                $this->container->get('flash')->addMessage('error', $errorText);
                return $response->withHeader('Location', '/profile')->withStatus(302);
            }

            $db->beginTransaction();

            try {
                $currentDateTime = new \DateTime();
                $update = $currentDateTime->format('Y-m-d H:i:s.v');

                $db->update(
                    'users_contact',
                    [
                        'first_name' => $data['owner']['first_name'],
                        'last_name' => $data['owner']['last_name'],
                        'org' => $data['owner']['org'],
                        'street1' => $data['owner']['street1'],
                        'city' => $data['owner']['city'],
                        'sp' => $data['owner']['sp'],
                        'pc' => $data['owner']['pc'],
                        'cc' => strtolower($data['owner']['cc']),
                        'voice' => $data['owner']['voice'],
                        'email' => $data['owner']['email']
                    ],
                    [
                        'user_id' => $userId,
                        'type' => 'owner'
                    ]
                );

                $db->update(
                    'users_contact',
                    [
                        'first_name' => $data['billing']['first_name'],
                        'last_name' => $data['billing']['last_name'],
                        'org' => $data['billing']['org'],
                        'street1' => $data['billing']['street1'],
                        'city' => $data['billing']['city'],
                        'sp' => $data['billing']['sp'],
                        'pc' => $data['billing']['pc'],
                        'cc' => strtolower($data['billing']['cc']),
                        'voice' => $data['billing']['voice'],
                        'email' => $data['billing']['email']
                    ],
                    [
                        'user_id' => $userId,
                        'type' => 'billing'
                    ]
                );
                
                $db->update(
                    'users_contact',
                    [
                        'first_name' => $data['tech']['first_name'],
                        'last_name' => $data['tech']['last_name'],
                        'org' => $data['tech']['org'],
                        'street1' => $data['tech']['street1'],
                        'city' => $data['tech']['city'],
                        'sp' => $data['tech']['sp'],
                        'pc' => $data['tech']['pc'],
                        'cc' => strtolower($data['tech']['cc']),
                        'voice' => $data['tech']['voice'],
                        'email' => $data['tech']['email']
                    ],
                    [
                        'user_id' => $userId,
                        'type' => 'tech'
                    ]
                );
                
                $db->update(
                    'users_contact',
                    [
                        'first_name' => $data['abuse']['first_name'],
                        'last_name' => $data['abuse']['last_name'],
                        'org' => $data['abuse']['org'],
                        'street1' => $data['abuse']['street1'],
                        'city' => $data['abuse']['city'],
                        'sp' => $data['abuse']['sp'],
                        'pc' => $data['abuse']['pc'],
                        'cc' => strtolower($data['abuse']['cc']),
                        'voice' => $data['abuse']['voice'],
                        'email' => $data['abuse']['email']
                    ],
                    [
                        'user_id' => $userId,
                        'type' => 'abuse'
                    ]
                );

                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during update: ' . $e->getMessage());
                return $response->withHeader('Location', '/profile')->withStatus(302);
            }

            $this->container->get('flash')->addMessage('success', 'User ' . $username . ' has been updated successfully on ' . $update);
            return $response->withHeader('Location', '/profile')->withStatus(302);
        }
    }

}