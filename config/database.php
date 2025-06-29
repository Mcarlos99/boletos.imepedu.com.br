<?php
/**
 * Sistema de Boletos IMEPEDU - Configuração do Banco de Dados
 * Arquivo: config/database.php
 * 
 * Classe responsável pela conexão e gerenciamento do banco de dados
 */

class Database {
    // Configurações de conexão
    private $host = 'localhost';
    private $dbname = 'boletodb';
    private $username = 'boletouser';
    private $password = 'gg3V6cNafyqsukXEJCcQ';
    private $charset = 'utf8mb4';
    private $port = 3306;
    
    // Instância da conexão
    private $connection = null;
    private static $instance = null;
    
    // Configurações PDO
    private $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        PDO::ATTR_TIMEOUT => 30
    ];
    
    /**
     * Construtor - Estabelece conexão com o banco
     */
    public function __construct() {
        $this->connect();
    }
    
    /**
     * Singleton pattern para garantir uma única instância
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Estabelece conexão com o banco de dados
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->dbname};charset={$this->charset}";
            
            $this->connection = new PDO($dsn, $this->username, $this->password, $this->options);
            
            // Define timezone para São Paulo
            $this->connection->exec("SET time_zone = '-03:00'");
            
            // Log de conexão bem-sucedida (apenas em desenvolvimento)
            if ($this->isDevelopment()) {
                error_log("Conexão com banco de dados estabelecida com sucesso: " . date('Y-m-d H:i:s'));
            }
            
        } catch (PDOException $e) {
            // Log do erro
            error_log("Erro de conexão com banco de dados: " . $e->getMessage());
            
            // Em produção, não expor detalhes do erro
            if ($this->isDevelopment()) {
                throw new Exception("Erro de conexão com banco de dados: " . $e->getMessage());
            } else {
                throw new Exception("Erro interno do servidor. Tente novamente em alguns instantes.");
            }
        }
    }
    
    /**
     * Retorna a instância da conexão PDO
     */
    public function getConnection() {
        // Verifica se a conexão ainda está ativa
        if ($this->connection === null) {
            $this->connect();
        }
        
        try {
            // Testa a conexão
            $this->connection->query('SELECT 1');
        } catch (PDOException $e) {
            // Reconecta se a conexão foi perdida
            $this->connect();
        }
        
        return $this->connection;
    }
    
    /**
     * Executa uma query preparada
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Erro na query: " . $e->getMessage() . " | SQL: " . $sql);
            throw new Exception("Erro ao executar consulta no banco de dados");
        }
    }
    
    /**
     * Busca um único registro
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Busca múltiplos registros
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Executa uma query de inserção e retorna o último ID inserido
     */
    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->getConnection()->lastInsertId();
    }
    
    /**
     * Executa uma query de atualização ou exclusão e retorna o número de linhas afetadas
     */
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Inicia uma transação
     */
    public function beginTransaction() {
        return $this->getConnection()->beginTransaction();
    }
    
    /**
     * Confirma uma transação
     */
    public function commit() {
        return $this->getConnection()->commit();
    }
    
    /**
     * Desfaz uma transação
     */
    public function rollback() {
        return $this->getConnection()->rollback();
    }
    
    /**
     * Verifica se está em uma transação
     */
    public function inTransaction() {
        return $this->getConnection()->inTransaction();
    }
    
    /**
     * Executa múltiplas operações em uma transação
     */
    public function transaction($callback) {
        try {
            $this->beginTransaction();
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            if ($this->inTransaction()) {
                $this->rollback();
            }
            throw $e;
        }
    }
    
    /**
     * Testa a conexão com o banco
     */
    public function testConnection() {
        try {
            $stmt = $this->getConnection()->query('SELECT 1 as test');
            $result = $stmt->fetch();
            return $result['test'] === 1;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Retorna informações sobre o banco de dados
     */
    public function getDatabaseInfo() {
        try {
            $info = [];
            
            // Versão do MySQL
            $stmt = $this->getConnection()->query('SELECT VERSION() as version');
            $info['mysql_version'] = $stmt->fetch()['version'];
            
            // Nome do banco
            $info['database_name'] = $this->dbname;
            
            // Charset
            $stmt = $this->getConnection()->query('SELECT @@character_set_database as charset');
            $info['charset'] = $stmt->fetch()['charset'];
            
            // Timezone
            $stmt = $this->getConnection()->query('SELECT @@time_zone as timezone');
            $info['timezone'] = $stmt->fetch()['timezone'];
            
            // Número de tabelas
            $stmt = $this->getConnection()->query("SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = '{$this->dbname}'");
            $info['table_count'] = $stmt->fetch()['table_count'];
            
            return $info;
        } catch (Exception $e) {
            return ['error' => 'Não foi possível obter informações do banco'];
        }
    }
    
    /**
     * Verifica se uma tabela existe
     */
    public function tableExists($tableName) {
        try {
            $sql = "SELECT COUNT(*) as count FROM information_schema.tables 
                    WHERE table_schema = ? AND table_name = ?";
            $stmt = $this->query($sql, [$this->dbname, $tableName]);
            $result = $stmt->fetch();
            return $result['count'] > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Executa backup das tabelas principais
     */
    public function backup($tables = []) {
        if (empty($tables)) {
            $tables = ['alunos', 'cursos', 'matriculas', 'boletos', 'administradores', 'logs'];
        }
        
        $backup = "-- Backup do Sistema de Boletos IMEPEDU\n";
        $backup .= "-- Data: " . date('Y-m-d H:i:s') . "\n\n";
        $backup .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                // Estrutura da tabela
                $stmt = $this->getConnection()->query("SHOW CREATE TABLE `$table`");
                $row = $stmt->fetch();
                $backup .= "-- Estrutura da tabela `$table`\n";
                $backup .= "DROP TABLE IF EXISTS `$table`;\n";
                $backup .= $row['Create Table'] . ";\n\n";
                
                // Dados da tabela
                $stmt = $this->getConnection()->query("SELECT * FROM `$table`");
                $rows = $stmt->fetchAll();
                
                if (!empty($rows)) {
                    $backup .= "-- Dados da tabela `$table`\n";
                    $backup .= "INSERT INTO `$table` VALUES ";
                    
                    $values = [];
                    foreach ($rows as $row) {
                        $values[] = "('" . implode("','", array_map([$this->getConnection(), 'quote'], array_values($row))) . "')";
                    }
                    $backup .= implode(",\n", $values) . ";\n\n";
                }
            }
        }
        
        $backup .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $backup;
    }
    
    /**
     * Limpa logs antigos para otimizar performance
     */
    public function cleanupLogs($days = 30) {
        try {
            $sql = "DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
            $deletedRows = $this->execute($sql, [$days]);
            
            // Log da limpeza
            $this->query(
                "INSERT INTO logs (tipo, descricao, created_at) VALUES (?, ?, NOW())",
                ['cleanup', "Limpeza de logs: {$deletedRows} registros removidos"]
            );
            
            return $deletedRows;
        } catch (Exception $e) {
            error_log("Erro na limpeza de logs: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Otimiza tabelas do banco
     */
    public function optimizeTables() {
        try {
            $tables = ['alunos', 'cursos', 'matriculas', 'boletos', 'administradores', 'logs'];
            $optimized = [];
            
            foreach ($tables as $table) {
                if ($this->tableExists($table)) {
                    $this->getConnection()->query("OPTIMIZE TABLE `$table`");
                    $optimized[] = $table;
                }
            }
            
            return $optimized;
        } catch (Exception $e) {
            error_log("Erro na otimização de tabelas: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica se está em ambiente de desenvolvimento
     */
    private function isDevelopment() {
        return (
            $_SERVER['SERVER_NAME'] === 'localhost' ||
            strpos($_SERVER['SERVER_NAME'], 'dev') !== false ||
            strpos($_SERVER['SERVER_NAME'], 'test') !== false ||
            $_SERVER['SERVER_ADDR'] === '127.0.0.1'
        );
    }
    
    /**
     * Destrutor - Fecha a conexão
     */
    public function __destruct() {
        $this->connection = null;
    }
}

// Função helper para facilitar o uso global
function getDB() {
    return Database::getInstance();
}

// Testa a conexão na primeira execução (apenas em desenvolvimento)
if ((isset($_SERVER['REQUEST_METHOD']) || php_sapi_name() === 'cli')) {
    try {
        $db = Database::getInstance();
        if (!$db->testConnection()) {
            error_log("Falha no teste de conexão com banco de dados");
        }
    } catch (Exception $e) {
        error_log("Erro ao inicializar banco de dados: " . $e->getMessage());
        
        // Em ambiente de desenvolvimento, mostra o erro
        if (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost') {
            die("Erro de conexão com banco de dados: " . $e->getMessage());
        }
    }
}
?>