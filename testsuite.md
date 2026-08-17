Passo a passo para rodar os testes
1. Testes JS (funcionam agora, sem nada a instalar)

# Entrar na pasta do plugin
cd /home/williangringo/_work/braspag/bp-lastest/wp-content/plugins/woocommerce-braspag-dev

# Rodar todos os testes JS
PATH=~/.nvm/versions/node/v20.17.0/bin:$PATH npm run test:js
Resultado esperado: 76 passed, 5 suites.

Para rodar com watch (modo desenvolvimento):


PATH=~/.nvm/versions/node/v20.17.0/bin:$PATH npm run test:js:watch
2. Testes PHP unitários (requer instalar extensões primeiro)

# Instalar extensões PHP faltantes (uma vez só)
sudo apt-get install php8.3-xml php8.3-mbstring

# Rodar apenas os unitários
cd /home/williangringo/_work/braspag/bp-lastest/wp-content/plugins/woocommerce-braspag-dev
./vendor/bin/phpunit --testsuite Unit

# Rodar um grupo específico
./vendor/bin/phpunit --testsuite Unit --filter Blocks
./vendor/bin/phpunit --testsuite Unit --filter Webhook
./vendor/bin/phpunit --testsuite Unit --filter CreditCard
3. Testes PHP de integração (requer WordPress instalado)
Esses testes precisam de um ambiente WordPress de testes. Siga os passos:

3.1 Instalar WordPress test suite:


# Defina as variáveis do seu banco de dados
export DB_NAME=wordpress_test
export DB_USER=root
export DB_PASS=''
export DB_HOST=localhost

# Baixar a suite de testes do WP
bash bin/install-wp-tests.sh $DB_NAME $DB_USER "$DB_PASS" $DB_HOST latest
3.2 Ativar o bootstrap de integração:

Edite tests/bootstrap.php e troque os blocos conforme a instrução comentada no arquivo (comente o bloco atual e descomente o bloco WordPress).

3.3 Rodar:


WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit --testsuite Integration
4. Rodar tudo de uma vez (após extensões PHP instaladas)

cd /home/williangringo/_work/braspag/bp-lastest/wp-content/plugins/woocommerce-braspag-dev

# JS
PATH=~/.nvm/versions/node/v20.17.0/bin:$PATH npm run test:js

# PHP unitários
./vendor/bin/phpunit --testsuite Unit
Referência rápida
Suite	Comando	Pré-requisito
JS (Jest)	npm run test:js	Node.js v20 via PATH
PHP unitário	phpunit --testsuite Unit	php8.3-xml + php8.3-mbstring
PHP integração	phpunit --testsuite Integration	WordPress test suite + banco


ddev phpunit --testsuite Unit
