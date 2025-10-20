# Secure Card Encryption Service

## Overview
This project provides a short lived sealed envelope system to protect card data in transit between a mobile client and your API. The client requests a one time public key from the server then uses that public key to seal card data. The server opens the sealed message using the matching secret key which is stored only temporarily in Redis The design minimizes exposure to plaintext card data and forces single use server side keys that expire automatically after three minutes

## Key concepts
1. One time key pair per request
2. Redis TTL enforces three minute expiry whether the key is used or not
3. Atomic get and delete prevents key reuse and reduces race conditions
4. Client side uses libsodium sealing so only the server can decrypt
5. Rate limiting logging and basic card validation to reduce abuse

## Included files
- env.php sample environment variables
- conn.php server bootstrap and helpers
- key_new.php creates one time key pairs and stores private material in Redis
- submit.php accepts sealed payloads decrypts and validates card data
- database.php schema for events logs table and partition rotation
- App.jsx minimal React Native example showing how to request a key seal payload and submit it

## Security features and recommendations
- TLS only Ensure your endpoints run behind HTTPS Do not use plain HTTP in production
- Redis hardening Bind Redis to localhost or private network Use AUTH and ACLs where available Do not expose Redis to the public internet
- Master key option Use a server side master key to encrypt secret key material in Redis if you must persist it for any reason
- Atomic operations Use GETDEL or a Lua script to atomically fetch and delete keys to prevent replay
- Short TTL Keys expire in 180 seconds three minutes whether used or not
- Certificate pinning Implement certificate pinning on the mobile app to mitigate man in the middle attacks
- Avoid storing PAN or CVV in logs or persistent storage Consider tokenization or a PCI compliant provider so you never handle raw card data
- Monitor and alert Log key creation and consumption and alert on abnormal patterns

## Environment variables
create a env.php file and add this 
```
<?php
    $db_name = "card_encrypt";
    $db_user = "database User";
    $db_pass = "Database password";

    $flw_secret = "Your Flutterwave Secret key";
    $flw_encryption_key = "Your Flutterwave Encryption key";

    $redis_host = "127.0.0.1";
    $redis_port = 6379;
    $redis_password = "your redis password";

```

## Install and service setup
Run on a Debian based system
```
sudo apt update
sudo apt install php php-cli php-fpm php-redis redis-server
sudo systemctl enable --now redis-server
sudo phpenmod sodium
sudo systemctl restart php8.4-fpm
```

## Database
The project includes an events log table to audit key lifecycle and usage The table is partitioned by year and an event is configured to rotate partitions annually See database.php for schema and stored procedure

## Rate limiting and logging
conn.php contains a simple Redis backed rate limiter to limit requests per IP You should tune the max and window values for your traffic profile Logging stores events to MySQL events_logs to help triage suspicious activity

## Client integration notes
- Always use HTTPS endpoints
- Request a key from key_new.php then call submit.php with the sealed payload
- Verify server identity via certificate pinning or signed key responses
- Do not retain the private key or secret material on the client

## Production hardening checklist
1. Disable PHP display errors set display_errors to 0 in production
2. Use strong random master key managed by a secrets manager
3. Restrict Redis network access to localhost or a VPC
4. Require Redis AUTH and restrict commands with ACLs
5. Use HSTS and modern TLS configurations
6. Sign and verify public key responses if possible
7. Enable monitoring alerting and periodic key rotation drills
8. Use a PCI compliant card tokenization provider where possible

## Troubleshooting
- key expired errors Ensure system clocks are synchronized across app server and Redis Use NTP
- Redis auth errors Check REDIS_AUTH environment variable and Redis logs
- Decrypt errors Ensure the client uses the public key returned for the same key id and that no data was altered during transport

## Auditing and testing
- Run regular authorized penetration tests
- Configure alerting for high rates of unused keys repeated decrypt failures and many keys created from the same IP
- Keep event logs for a retention period that meets your compliance needs

## Privacy and compliance
This project handles sensitive payment data You are responsible for ensuring compliance with local law and any payment industry obligations such as PCI DSS Consider using a payment processor or tokenization to reduce compliance scope

## Contact and support
For support or custom hardening assistance contact the project maintainer Provide environment details and relevant logs when requesting help

## License
Provided as is Use at your own risk No warranty expressed or implied
