# amtgard-bastion-idp
A bastion IDP that allows users to create accounts associated with Amtgard metadata and log into Amtgard digital properties

## Development
```php
composer install
vendor/robmorgan/phinx/bin/phinx migrate
sudo docker-compose -f docker-compose.dev.yml up -d --build
```

Server will be on http://localhost:37080/