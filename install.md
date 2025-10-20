# Install Guide

This document shows how to install and configure the Secure Card Encryption Service on a Debian or Ubuntu server. Follow the steps exactly and run commands as a user with sudo privileges.

## Overview
1. Install system packages
2. Configure PHP and Redis
3. Set environment variables
4. Create database and tables
5. Configure PHP FPM service
6. Deploy the PHP files and RN app example
7. Security checklist

## 1 Install system packages
Run these commands
```bash
sudo apt update
sudo apt install -y php php-cli php-fpm php-redis redis-server mysql-server unzip git
```

Enable services
```bash
sudo systemctl enable --now redis-server
sudo systemctl enable --now php8.4-fpm
sudo systemctl enable --now mysql
```

## 2 Configure PHP
Enable sodium extension and restart FPM
```bash
sudo phpenmod sodium
sudo systemctl restart php8.4-fpm
```

Adjust php ini for production
Edit the active php ini file for FPM usually at
```
/etc/php/8.4/fpm/php.ini
```
Set these values
```
display_errors = 0
log_errors = 1
error_log = /var/log/php_errors.log
```
Restart FPM after changes
```bash
sudo systemctl restart php8.4-fpm
```

## 3 Secure Redis
Bind to localhost and set a password
Edit `/etc/redis/redis.conf` and set
```
bind 127.0.0.1 ::1
requirepass YOUR_STRONG_PASSWORD
```
Restart redis
```bash
sudo systemctl restart redis-server
```

Optional ACL example for Redis 6 and above
```
user default on >YOUR_STRONG_PASSWORD ~* +@all
```

## 4 Configure MySQL and database
Secure MySQL
```bash
sudo mysql_secure_installation
```
Create database and user
```sql
sudo mysql -u root -p <<'SQL'
CREATE DATABASE card_encrypt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'card_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON card_encrypt.* TO 'card_user'@'localhost';
FLUSH PRIVILEGES;
SQL
```
Import events log schema
```bash
mysql -u card_user -p card_encrypt < database.php
```

## 5 Environment variables
Create an env.php file outside web root and set values
```
<?php
$db_name = "card_encrypt";
$db_user = "card_user";
$db_pass = "strong_password_here";
?>
```
For additional configuration use system environment variables for Redis and master key
```
export REDIS_HOST=127.0.0.1
export REDIS_PORT=6379
export REDIS_AUTH=YOUR_STRONG_PASSWORD
export MASTER_KEY_B64=BASE64_MASTER_KEY
export APP_ORIGIN=https://yourapp.example.com
```
Store these in a secure place such as a systemd service file or a secrets manager

## 6 Generate a server master key
This key is used to encrypt secret material stored in Redis
Generate using PHP interactive or CLI
```bash
php -r 'echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;'
```
Set the value to MASTER_KEY_B64 in the environment

## 7 Deploy PHP files
Place your project files in the web root or a dedicated site directory for example `/var/www/encryption`
Set proper ownership and permissions
```bash
sudo mkdir -p /var/www/encryption
sudo chown -R www-data:www-data /var/www/encryption
sudo chmod -R 750 /var/www/encryption
```
Copy the PHP files `env.php conn.php key_new.php submit.php database.php` to that directory

## 8 Configure Nginx proxy for TLS
Install nginx
```bash
sudo apt install -y nginx
```
Create a site file in `/etc/nginx/sites-available/encryption`
Example server block
```
server {
	listen 80;
	server_name yourapp.example.com;
	return 301 https://$host$request_uri;
}

server {
	listen 443 ssl;
	server_name yourapp.example.com;

	ssl_certificate /etc/letsencrypt/live/yourapp.example.com/fullchain.pem;
	ssl_certificate_key /etc/letsencrypt/live/yourapp.example.com/privkey.pem;
	include /etc/letsencrypt/options-ssl-nginx.conf;
	ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

	root /var/www/encryption;
	index index.php;

	location / {
		try_files $uri $uri/ =404;
	}

	location ~ \.php$ {
		include fastcgi_params;
		fastcgi_pass unix:/run/php/php8.4-fpm.sock;
		fastcgi_index index.php;
		fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
	}
}
```
Enable site and reload
```bash
sudo ln -s /etc/nginx/sites-available/encryption /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

Obtain TLS certificates via certbot
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d yourapp.example.com
```

## 9 React Native client
In the app update baseURL to your HTTPS endpoint for example
```
const baseURL = 'https://yourapp.example.com/encryption';
```
Add certificate pinning or use a networking library that supports pinning

## 10 Service hardening and monitoring
1. Ensure server clocks are synced
```bash
sudo apt install -y ntp
sudo systemctl enable --now ntp
```
2. Configure log rotation for PHP and nginx logs
3. Configure firewall to allow only required ports
```bash
sudo ufw allow 22
sudo ufw allow 80
sudo ufw allow 443
sudo ufw enable
```
4. Restrict Redis access to localhost or private network
5. Use fail2ban to block repeated abusive requests

## 11 Backup and rotation
1. Backup MySQL regularly using mysqldump
2. Rotate Redis persistence files if using AOF or RDB
3. Rotate master key if needed and reencrypt Redis contents carefully

## 12 Final checks
1. Test key creation and submit flow end to end
2. Confirm keys expire after three minutes even when unused
3. Confirm logs record key creation and decryption events
4. Confirm TLS is valid and certificate pinning works in the app

## Support
If you want I can produce systemd unit files example for running the app or scripts to automate deployment