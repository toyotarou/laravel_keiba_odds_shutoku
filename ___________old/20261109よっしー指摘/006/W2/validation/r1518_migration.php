<?php
declare(strict_types=1);
namespace Illuminate\Database\Migrations {
    class Migration {}
}
namespace Illuminate\Database\Schema {
    use Illuminate\Support\Facades\Schema;
    final class Blueprint {
        private string $column;
        private int $length;
        public function char(string $column,int $length):self {
            if ($column!=='w2_send_proof' || $length!==65) throw new \LogicException('unexpected definition');
            $this->column=$column;$this->length=$length;
            return $this;
        }
        public function nullable():void {
            Schema::$db->exec('ALTER TABLE baganriki_ai_attempts ADD COLUMN '.$this->column.' CHAR('.$this->length.') NULL');
        }
    }
}
namespace Illuminate\Support\Facades {
    use Illuminate\Database\Schema\Blueprint;
    final class Schema {
        public static \PDO $db;
        public static function hasColumn(string $table,string $name):bool {
            if ($table!=='baganriki_ai_attempts') throw new \LogicException('unexpected table');
            $cols=self::$db->query('PRAGMA table_info(baganriki_ai_attempts)')->fetchAll(\PDO::FETCH_ASSOC);
            return in_array($name,array_column($cols,'name'),true);
        }
        public static function table(string $name,callable $modify):void {
            if ($name!=='baganriki_ai_attempts') throw new \LogicException('unexpected table');
            $modify(new Blueprint());
        }
    }
}
namespace {
    use Illuminate\Support\Facades\Schema;
    require dirname(__DIR__).'/integration/2026_11_05_000018_add_w2_send_proof.php';
    $db=Schema::$db=new \PDO('sqlite::memory:',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE baganriki_ai_attempts(token TEXT PRIMARY KEY,status TEXT NOT NULL)");
    $db->exec("INSERT INTO baganriki_ai_attempts VALUES('old','RECEIVED')");
    $migration=new AddW2SendProof();$migration->up();
    $proof='U'.str_repeat('a',64);
    $db->prepare('INSERT INTO baganriki_ai_attempts VALUES(?,?,?)')->execute(['new','SENT',$proof]);
    $migration->up();$migration->down();
    $rows=$db->query('SELECT token,status,w2_send_proof FROM baganriki_ai_attempts ORDER BY rowid')->fetchAll(\PDO::FETCH_NUM);
    $column=$db->query('PRAGMA table_info(baganriki_ai_attempts)')->fetchAll(\PDO::FETCH_ASSOC)[2]??[];
    if ($rows!==[['old','RECEIVED',null],['new','SENT',$proof]] || $column['type']!=='CHAR(65)' || (int)$column['notnull']!==0) {
        throw new \LogicException('repeat or rollback damaged the stored evidence');
    }
    echo "PASS migration on an existing SQLite table, repeat up, code rollback, old rows and used proof preserved.\n";
}
