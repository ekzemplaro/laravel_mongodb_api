					Jul/01/2018

# laravel_mongodb_api


How to install

	cd laravel_mongodb_api
	composer install
	cp .env.example .env

How to start the server

	MongoDB must be running.

		user		scott
		password	tiger123
		collection	city

	php artisan serve --host 0.0.0.0


Client

	curl http://localhost:8000/api/city
	curl http://localhost:8000/api/all
	curl http://localhost:8000/api/find/5b2c483e6fc1214d33e6151c
	curl http://localhost:8000/api/where/久喜
	curl http://localhost:8000/api/between/30000/50000	
