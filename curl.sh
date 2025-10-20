# charge card

curl -X POST http://localhost/card-encryption/api/src/process-card.php \
	-H "Content-Type: application/json" \
	-d '{
		"card_number":"5531886652142950",
		"cvv":"564",
		"expiry_month":"09",
		"expiry_year":"32",
		"amount":"100",
		"currency":"NGN",
		"email":"test@example.com",
		"fullname":"Test User"
	}'

# You’ll get need_pin. Resend with a PIN:

curl -X POST http://localhost/card-encryption/api/src/process-card.php \
	-H "Content-Type: application/json" \
	-d '{
		"card_number":"5531886652142950",
		"cvv":"564",
		"expiry_month":"09",
		"expiry_year":"32",
		"amount":"100",
		"currency":"NGN",
		"email":"test@example.com",
		"fullname":"Test User",
		"pin":"3310"
	}'




# If it then asks for OTP, call:
curl -X POST http://localhost/card-encryption/api/src/process-card.php \
	-H "Content-Type: application/json" \
	-d '{"action":"validate","flw_ref":"FLW-MOCK-617c1e4be2a6b9ea8ba36e10072cec7c","otp":"12345"}'




# AVS flow

curl -X POST http://localhost/card-encryption/api/src/process-card.php \
	-H "Content-Type: application/json" \
	-d '{
		"card_number":"4556052704172643",
		"cvv":"899",
		"expiry_month":"09",
		"expiry_year":"32",
		"amount":"100",
		"currency":"NGN",
		"email":"test@example.com",
		"fullname":"Test User",
        "city":"Lagos",
        "address":"123 Main St",
        "state":"Lagos",
        "country":"NG",
        "zipcode":"12345"
	}'
