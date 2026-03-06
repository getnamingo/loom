# JSON for Development

```json
{
    "ssl": true,
    "cert_file": "/etc/ssl/certs/example.crt",
    "key_file": "/etc/ssl/private/example.key",
    "auth": {
        "username": "your-epp-username",
        "password": "your-epp-password"
    },
    "client_id": "EXAMPLE-REG",
    "contactRoles": ["registrant", "admin", "tech", "billing"],
    "required_fields": {
        "id_number": {
            "label": "Finnish ID Number",
            "type": "text",
            "required": true,
            "hint": "Required for all .fi registrations"
        },
        "org_name": {
            "label": "Organization Name",
            "type": "text",
            "required": false
        }
    }
}
```

For thin registries: `"contactRoles": []`

```json
{
    ".test": {
        "register": {
            "1": 10,
            "2": 19,
            "3": 28,
            "4": 37,
            "5": 45,
            "10": 85
        },
        "renew": {
            "1": 11,
            "2": 21,
            "3": 30,
            "5": 49
        },
        "transfer": {
            "1": 9.5
        },
        "restore": {
            "1": 30
        },
        "premium": {
            "tier1": {
                "register": {
                    "1": 100,
                    "2": 195
                },
                "renew": {
                    "1": 95
                },
                "transfer": {
                    "1": 90
                },
                "restore": {
                    "1": 250
                }
            },
            "tier2": {
                "register": {
                    "1": 250
                },
                "renew": {
                    "1": 240
                },
                "transfer": {
                    "1": 230
                },
                "restore": {
                    "1": 400
                }
            }
        }
    }
}
```

```json
{
  "domain": "example.test",
  "years": 2,
  "registry_domain_id": 'abc123',
  "reseller": '',
  "reseller_url": '',
  "authcode": "XyZ123ABC",
  "status": ["clientTransferProhibited", "clientUpdateProhibited"],

  "contacts": {
    "registrant": {
      "registry_id": "REG-123456",
      "name": "John Doe",
      "email": "john@example.com",
      "phone": "+1.123456789",
      "address": "123 Main St, Metropolis, US"
    },
    "admin": {
      "registry_id": "ADM-654321",
      "name": "Alice Admin",
      "email": "alice.admin@example.com",
      "phone": "+1.555001100",
      "address": "456 Admin Ave, Gotham, US"
    },
    "tech": {
      "registry_id": "TECH-789012",
      "name": "Tech Guy",
      "email": "tech@example.com",
      "phone": "+1.800900900",
      "address": "789 Tech Blvd, Silicon Valley, US"
    },
    "billing": {
      "registry_id": "BILL-112233",
      "name": "Billing Person",
      "email": "billing@example.com",
      "phone": "+1.222333444",
      "address": "321 Billing Rd, Capital City, US"
    }
  },

  "nameservers": [
    "ns1.example-dns.com",
    "ns2.example-dns.com",
    "ns3.example-dns.com",
    "ns4.example-dns.com"
  ],

  "dnssec": {
    "enabled": false,
    "ds_records": []
  },

  "notes": "First registration of the domain for testing."
}
```

```json
"dnssec": {
  "enabled": true,
  "ds_records": [
    {
      "interface": "dsData",
      "keytag": 12345,
      "alg": 13,
      "digesttype": 2,
      "digest": "ABCD1234EF567890"
    },
    {
      "interface": "keyData",
      "flags": 257,
      "protocol": 3,
      "keydata_alg": 8,
      "pubkey": "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A..."
    }
  ]
}
```

order

```json
{
  "type": "domain_register",
  "domain": "example.test",
  "years": 2,
  "tld": "test",
  "provider": "namingo",
  "error_message": null,
  "notes": "Customer requested domain registration",
  "contacts": {
    "registrant": {
      "name": "John Doe",
      "email": "john@example.com",
      "phone": "+1.123456789",
      "address": "123 Main St, Metropolis, US"
    },
    "admin": {
      "name": "Alice Admin",
      "email": "alice.admin@example.com",
      "phone": "+1.555001100",
      "address": "456 Admin Ave, Gotham, US"
    },
    "tech": {
      "name": "Tech Guy",
      "email": "tech@example.com",
      "phone": "+1.800900900",
      "address": "789 Tech Blvd, Silicon Valley, US"
    },
    "billing": {
      "name": "Billing Person",
      "email": "billing@example.com",
      "phone": "+1.222333444",
      "address": "321 Billing Rd, Capital City, US"
    }
  },
  "nameservers": [
    "ns1.exampledns.com",
    "ns2.exampledns.com"
  ],
  "dnssec": {
    "enabled": false,
    "ds_records": []
  }
}
```

```json
{
  "type": "domain_transfer",
  "domain": "example.test",
  "provider": "namingo",
  "error_message": null,
  "notes": "Customer requested domain registration"
}
```

