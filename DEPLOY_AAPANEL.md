# Instruções de Deploy no AAPanel com Nginx + MariaDB

Este documento auxilia a hospedar sua aplicação compilada (Frontend) e o novo Backend (PHP) no AAPanel.
Tudo funcionará no mesmo domínio/subdomínio usando regras de proxy do Nginx.

## 1. Banco de Dados (MariaDB)
1. No AAPanel, vá em **Databases** e crie um novo banco (ex: `snakebet`).
2. Importe o arquivo `database.sql` gerado pelo Node.js que contem a estrutura das tabelas.
3. Crie um usuário e senha forte.

## 2. Preparar os Arquivos (PHP Backend + Frontend Compile)
1. Na raiz do projeto, atualize o arquivo `.env` para apontar o `VITE_API_URL` para o `/api` (ou pode deixar vazio se estiver via proxy na mesma porta).
2. Compile o frontend executando no terminal:
   ```bash
   npm run build
   ```
3. Uma pasta `dist/` será criada. Compacte (ZIP) o conteúdo da pasta `dist/` junto com a pasta `php_backend/`.
4. No AAPanel, crie seu Site (ex: `app.seusite.com`). O Document Root deve ser a pasta onde você enviou os arquivos. Coloque o conteúdo de `dist/` na raiz do site, e a pasta `php_backend/` dentro dessa mesma raiz (ou para ficar invisível ao usuário, deixe como instruído abaixo).

## 3. Configuração do Nginx (AAPanel)
Para que o Frontend em React (index.html) e a API em PHP funcionem perfeitamente juntos, clique em **Site -> Configuration -> URL rewrite** (ou **Config -> Config**) no AAPanel do seu site e adicione a seguinte regra:

```nginx
location /api {
    # Todas as requisições para /api serão enviadas para o script api.php
    rewrite ^/api/(.*)$ /php_backend/api.php?$args last;
}

location / {
    # Suporte para rota do React Router (SPA)
    try_files $uri $uri/ /index.html;
}
```

## 4. Variáveis de Ambiente (PHP)
O AAPanel usa PHP-FPM. Para que o `db.php` e a `api.php` encontrem o banco de dados e as senhas (já que não usamos Node e `.env`), você pode definir essas variáveis diretamente na configuração PHP ou na configuração FastCGI do Nginx.

No arquivo de configuração do site no AAPanel (aba Config), dentro do bolco `location ~ \.php$`, adicione:
```nginx
fastcgi_param DB_HOST "localhost";
fastcgi_param DB_NAME "nome_do_banco";
fastcgi_param DB_USER "usuario_do_banco";
fastcgi_param DB_PASSWORD "sua_senha_forte";
fastcgi_param ADMIN_PASSWORD "admin123";
fastcgi_param ADMIN_USERNAME "admin";
fastcgi_param JWT_SECRET "SuaChaveSuperSecretaSnakeBet2024";
```

Pronto! Seu Nginx hospedará a SPA no `/` e a nova API PHP no `/api` usando o MariaDB nativo do AAPanel!
Evitando assim a necessidade de rodar o Node.js.
