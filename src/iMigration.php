<?php

namespace Oniti\Migrations;

interface iMigration
{
    public function up() : string;
    public function down() : string;
}