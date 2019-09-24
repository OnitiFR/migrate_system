<?php
namespace Oniti\Migrations;

require_once(__DIR__.'/../../private/global/core/env.php');

use PDO;
use Exception;
use PDOException;
use Throwable;
use Oniti\Migrations\iMigration;

class Migration{
    /**
     * Connection a la base de donnée
     */
    private $conn;
    private $dir_migrations = __DIR__.'/../../migrations/';

    function __construct() {
		try {
			$this->conn = new PDO('mysql:host='.env('MYSQL_HOST').';dbname='.env('MYSQL_DB'), env('MYSQL_USER'), env('MYSQL_PASSWORD'), array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'));
			$this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		}
		catch(PDOException $e) {
			throw new Exception('ERREUR PDO dans ' . $e->getFile() . ' L.' . $e->getLine() . ' : ' . $e->getMessage());
		}
    }

    /**
     * Permet de revenir sur des migrations antérieur.
     */
    public function rollback($step = null){
        /**
         * Début de la transation
         */
        $this->startTransaction();

        /**
         * Créer la table si elle n'existe pas 
         */
        $this->createIfNotExisteMigrationTable();

        $step = $step ?? $this->getMaxCurrentStep();
        $file_already_pass = $this->getMigrations($step,'>=',' ORDER BY id desc');
        $files = $this->assocBaseNameObject($file_already_pass);

        foreach ($file_already_pass as $file_name) {
            foreach ($files as $config) {
                $file = $config['file'];
                $obj = $config['object'];
                if($file === $file_name){
                    try {
                        $this->info('RollBack du fichier : '.$file.' ...');
                        $this->executeQuery($obj->down());
                        $this->executeQuery("DELETE FROM migrations WHERE file = :file;",[":file" => $file]);
                        $this->success('RollBack Finie.');
                    } catch (Throwable $th) {
                        $this->error($th->getMessage());
                        $this-> endTransation(true);
                        throw $th;
                    }
                }
            }
        }

        $this-> endTransation(false);
    }

    /**
     * Débute une transaction
     */
    private function startTransaction(){
        $this->success('Début de la transation.');
        $this->executeQuery('START TRANSACTION; SET autocommit = OFF;');
    }
    /**
     * Met fin a la transation
     */
    private function endTransation(bool $rollback){
        if($rollback){
            $this->success('Fin de la transaction ROLLBACK.');
            $this->executeQuery('ROLLBACK;');
        }
        else {
            $this->success('Fin de la transaction COMMIT.');
            $this->executeQuery('COMMIT;');
        }
    }

    /**
     * Migre le fichiers manquant.
     */
    public function migrate(){
        /**
         * Début de la transation
         */
        $this->startTransaction();

        /**
         * Créer la table si elle n'existe pas 
         */
        $this->createIfNotExisteMigrationTable();
        
        $new_step = $this->getMaxCurrentStep() + 1;

        $file_already_pass = $this->getMigrations($new_step,'<');
        
        foreach ($this->assocBaseNameObject([],$file_already_pass) as $config) {
            $file = $config['file'];
            $obj = $config['object'];
            try {
                $this->info('Migration du fichier : '.$file.' ...');
                $this->executeQuery($obj->up());
                $this->executeQuery("INSERT INTO migrations (file,step) VALUES ( :file, :step)",[":file" => $file, ':step' => $new_step]);
                $this->success('Migration Finie.');
            } catch (Throwable $th) {
                $this->error($th->getMessage());
                $this-> endTransation(true);
                throw $th;
            }
        }

        $this-> endTransation(false);
        
    }

    /**
     * Retourne l'étape courrante
     */
    private function getMaxCurrentStep(){
        $max_step_result = $this->executeSelect('select max(step) max_step from migrations');

        return $max_step_result[0]['max_step'] ?? 0;
    }

    /**
     * Retourne les migration en base avec l'étape indiquée
     */
    private function getMigrations($step,$sign, $options = null){
        $result = [];
        foreach ($this->executeSelect('select file from migrations where step '.$sign.' :step '.($options ?? '').';',[':step' => $step]) as $file) {
            $result[] = $file['file'];
        }
        return $result;
    }

    /**
     * Retourne les files associer a leur objet
     */
    private function assocBaseNameObject($files_to_keep = [],$files_to_ignore = []){
        $liste = [];
        foreach ($this->listeFiles() as $file) {
            if((empty($files_to_keep) || in_array(basename($file),$files_to_keep)) && !in_array(basename($file),$files_to_ignore)){
                require_once($file);
                $classeName = basename($file);
                $classeName = 'Migrations\Migrations\\'.explode('.',explode('_',$classeName)[1])[0];
                $object =new $classeName;
                if($object instanceof iMigration){
                    $liste[] =[
                        'file' => basename($file),
                        'object' => new $classeName
                    ];
                }else throw new Exception('La classe '.$classeName.' doit compoter l\'interface iMigration');
                
            }
            
        }
        usort($liste,function($a,$b){
            return strcmp($a['file'],$b['file']);
        });
        return $liste;
    } 

    /**
     * Retourne les files dans le dossier de migration
     */
    private function listeFiles(){
        $files = [];
        if (is_dir($this->dir_migrations)) {
            if ($dh = opendir($this->dir_migrations)) {
                while (($file = readdir($dh)) !== false) {
                    if(!is_dir($this->dir_migrations . $file))  $files[] = $this->dir_migrations . $file;
                }
                closedir($dh);
            }
        }
        $files = array_reverse($files);
        return $files;
    }
    
    /**
     * Créer la table de migration si cette dernière n'existe pas.
     */
    private function createIfNotExisteMigrationTable(){
        try {
			$query = 'SELECT 1 FROM migrations LIMIT 1;';
            $this->executeSelect($query);
		}
		catch(PDOException $e) {
			if($e->getCode() === '42S02'){
               $this->createTableMigration();
            }else{
                throw $e;
            }
		}
    }

    /**
     * exécute une query sql
     */
    private function executeQuery($query,$params = []){
        $prepared_query = $this->conn->prepare($query);

        foreach ($params as $key => $value) {
            $prepared_query->bindValue($key,$value);
        }

        $prepared_query->execute();
        return $prepared_query;
    }

    private function executeSelect($query,$params = []){
        return $this->executeQuery($query,$params)->fetcHAll(PDO::FETCH_ASSOC);
    }

    /**
     * Créer la table des migrations
     */
    private function createTableMigration(){
        $this->info("Création de la table de Migration...");
        $query = '
        CREATE TABLE migrations
        (
            id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
            file VARCHAR(255) UNIQUE,
            step INT,
            UNIQUE (file)
        )';
        try {
            $this->executeQuery($query);
            $this->success('Table migrations créée.');
        } catch (Throwable $th) {
            $this->error($th->geMessage());
            throw $th;
        }
        
    }
    /**
     * Affiche un message d'information
     */
    private function info($message){
        $this->printSting("0;33m",$message);
    }
    /**
     * Affiche un message de succes
     */
    private function success($message){
        $this->printSting("10;32m",$message);
    }
    /**
     * Affiche un message d'erreur
     */
    private function error($message){
        $this->printSting("1;31m",$message);
    }
    /**
     * Affiche une chaine de caractère sur la console avec la coloration demandée
     */
    private function printSting($color,$message){
        echo "\033[".$color.$message."\033[0m".PHP_EOL;
    }
}
