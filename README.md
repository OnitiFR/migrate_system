### Installation

``` shell
composer require oniti/migration_manager
ln -s vendor/oniti/migration_manager/src/migrate migrate
```

### Env requiered

MYSQL_HOST=localhost
MYSQL_DB=MaBdd
MYSQL_USER=MonUser
MYSQL_PASSWORD=MonPassword

### Migration

Un nouveau systeme de migration a été mis en place avant toutes choses veuillez exécuter la commande suivante

il vous suffit ensuite de créer vos migrations dans le dossiers ```migrations```

en les préfixant du numéro de migration par exemple :

1_CreateFieldRefDossier.php

``` php
<?php

use Oniti\Migrations\iMigration;

class CreateFieldRefDossier implements iMigration {

    public function up() : string{
        return "ALTER TABLE `compte` ADD `ref_dossier` VARCHAR(255) NULL AFTER `active`";
    }

    public function down() : string{
        return "ALTER TABLE `compte` DROP `ref_dossier`;";
    }
}
```

ensuite pour migrer il suffit de faire :
``` shell
php migrate
```

Pour le rollback
``` shell
php migrate --rollback
```
ou un rollback a une version stipulée
``` shell
php migrate --rollback --step=xxx
```