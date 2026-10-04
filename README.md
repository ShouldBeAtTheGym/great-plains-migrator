# Great Plains Migrator

Move one Microsoft Dynamics GP company that uses SalesPad into Odoo Enterprise.

The application reads Great Plains and builds the import files Odoo needs. You import each file on the matching Odoo screen. The checklist unlocks the next file after Odoo has the records, or after you skip that file. Great Plains is never written to.

## What it does

- Connects to Great Plains and Odoo, and checks that the SalesPad views are there.
- Builds the files in order: payment terms, categories, units, locations, customers, vendors, products, prices, inventory, sales documents, then purchasing.
- Leaves out rows that would stop an import, and gives you a report of those rows.
- Splits a very large file into parts Odoo can upload.
- After the first import, Step two counts new Great Plains rows so you can download just those.

Step two adds new rows. It does not remove or change rows that are already in Odoo.

## Install

1. Install PHP 8.3 or newer, Composer, MySQL 8, and the PHP drivers for MySQL (`pdo_mysql`) and Microsoft SQL Server (`sqlsrv`).
2. Download this application and run `composer install`.
3. Copy `.env.example` to `.env`.
4. Point the web server at the `public` folder.
5. Open the site. The product page asks for the MySQL host, database, user, and password. That database is for this application only. It is not the Great Plains server. The application creates the tables after the connection works.
6. Create a login, connect Great Plains, then connect Odoo. Start with the lit button.

To create the tables yourself instead, make an empty MySQL database and run `database/mysql/great_plains_migrator.sql` inside it. That file is only for an empty database. You can then put the connection in `.env`.

## Requirements

- A Great Plains company database with SalesPad installed. The exports read the `spv` views.
- An Odoo Enterprise database, and a user with an API key who can read external identifiers.
- PHP's SQL Server extension on the web server, because Great Plains is read from SQL Server.
