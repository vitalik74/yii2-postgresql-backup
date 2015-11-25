<?php

/**
 * Use this class for create database backup of Postgresql database
 * @author Vitaliy Koziy
 */
namespace vitalik74\backup;

use Yii;
use yii\base\Component;

class DoBackup extends Component
{

    /**
     * @var string Full path to your db backup file, \var\www\backup\db.sql
     */
    public $backupPath;

    /**
     * Create database backup file and save it
     */
    public function create($fileName)
    {
        try {
            $db = Yii::$app->db;
            parse_str(explode(':', str_replace(';', '&', $db->dsn))[1], $parse);
            $port = isset($parse['port']) ? 'port=' . $parse['port'] : '';
            $dbconn = pg_pconnect("host=$parse[host] $port dbname=$parse[dbname]
user=$db->username password=$db->password"); //connectionstring
        } catch (\Exception $exc) {
            echo "Can't connect to database.";
            exit();
        }
        $back = fopen($this->backupPath . '/' . $fileName, "w");
        $res = pg_query(" select relname as tablename
                    from pg_class where relkind in ('r')
                    and relname not like 'pg_%' and relname not like 
'sql_%' order by tablename");
        $str = "";
        while ($row = pg_fetch_row($res)) {
            $table = $row[0];
            $str .= "\n--\n";
            $str .= "-- Estrutura da tabela '$table'";
            $str .= "\n--\n";
            $str .= "\nDROP TABLE $table CASCADE;";
            $str .= "\nCREATE TABLE $table (";
            $res2 = pg_query("
    SELECT  attnum,attname , typname , atttypmod-4 , attnotnull 
,atthasdef ,adsrc AS def
    FROM pg_attribute, pg_class, pg_type, pg_attrdef WHERE 
pg_class.oid=attrelid
    AND pg_type.oid=atttypid AND attnum>0 AND pg_class.oid=adrelid AND 
adnum=attnum
    AND atthasdef='t' AND lower(relname)='$table' UNION
    SELECT attnum,attname , typname , atttypmod-4 , attnotnull , 
atthasdef ,'' AS def
    FROM pg_attribute, pg_class, pg_type WHERE pg_class.oid=attrelid
    AND pg_type.oid=atttypid AND attnum>0 AND atthasdef='f' AND 
lower(relname)='$table' ");
            while ($r = pg_fetch_row($res2)) {
                $str .= "\n" . $r[1] . " " . $r[2];
                if ($r[2] == "varchar") {
                    $str .= "(" . $r[3] . ")";
                }
                if ($r[4] == "t") {
                    $str .= " NOT NULL";
                }
                if ($r[5] == "t") {
                    $str .= " DEFAULT " . $r[6];
                }
                $str .= ",";
            }
            $str = rtrim($str, ",");
            $str .= "\n);\n";
            $str .= "\n--\n";
            $str .= "-- Creating data for '$table'";
            $str .= "\n--\n\n";


            $res3 = pg_query("SELECT * FROM \"$table\"");
            while ($r = pg_fetch_row($res3)) {
                $sql = "INSERT INTO $table VALUES ('";
                $sql .= implode("','", $r);
                $sql .= "');";
                $str = str_replace("''", "NULL", $str);
                $str .= $sql;
                $str .= "\n";
            }

            $res1 = pg_query("SELECT pg_index.indisprimary,
            pg_catalog.pg_get_indexdef(pg_index.indexrelid)
        FROM pg_catalog.pg_class c, pg_catalog.pg_class c2,
            pg_catalog.pg_index AS pg_index
        WHERE c.relname = '$table'
            AND c.oid = pg_index.indrelid
            AND pg_index.indexrelid = c2.oid
            AND pg_index.indisprimary");
            while ($r = pg_fetch_row($res1)) {
                $str .= "\n\n--\n";
                $str .= "-- Creating index for '$table'";
                $str .= "\n--\n\n";
                $t = str_replace("CREATE UNIQUE INDEX", "", $r[1]);
                $t = str_replace("USING btree", "|", $t);
                // Next Line Can be improved!!!
                $t = str_replace("ON", "|", $t);
                $Temparray = explode("|", $t);
                $str .= "ALTER TABLE ONLY " . $Temparray[1] . " ADD CONSTRAINT " .
                    $Temparray[0] . " PRIMARY KEY " . $Temparray[2] . ";\n";
            }
        }
        $res = pg_query(" SELECT
  cl.relname AS tabela,ct.conname,
   pg_get_constraintdef(ct.oid)
   FROM pg_catalog.pg_attribute a
   JOIN pg_catalog.pg_class cl ON (a.attrelid = cl.oid AND cl.relkind = 'r')
   JOIN pg_catalog.pg_namespace n ON (n.oid = cl.relnamespace)
   JOIN pg_catalog.pg_constraint ct ON (a.attrelid = ct.conrelid AND
   ct.confrelid != 0 AND ct.conkey[1] = a.attnum)
   JOIN pg_catalog.pg_class clf ON (ct.confrelid = clf.oid AND 
clf.relkind = 'r')
   JOIN pg_catalog.pg_namespace nf ON (nf.oid = clf.relnamespace)
   JOIN pg_catalog.pg_attribute af ON (af.attrelid = ct.confrelid AND
   af.attnum = ct.confkey[1]) order by cl.relname ");
        while ($row = pg_fetch_row($res)) {
            $str .= "\n\n--\n";
            $str .= "-- Creating relationships for '" . $row[0] . "'";
            $str .= "\n--\n\n";
            $str .= "ALTER TABLE ONLY " . $row[0] . " ADD CONSTRAINT " . $row[1] .
                " " . $row[2] . ";";
        }
        fwrite($back, $str);
        fclose($back);

        return $this->backupPath . '/' . $fileName;
    }

}
