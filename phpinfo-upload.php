<?php
// Arquivo: phpinfo-upload.php
// REMOVA ESTE ARQUIVO APÓS O USO!

echo "<h2>Configurações de Upload do PHP</h2>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Configuração</th><th>Valor Atual</th><th>Status</th></tr>";

$configs = [
    'max_file_uploads' => ['recomendado' => 50, 'minimo' => 20],
    'post_max_size' => ['recomendado' => '128M', 'minimo' => '32M'],
    'upload_max_filesize' => ['recomendado' => '10M', 'minimo' => '5M'],
    'max_input_vars' => ['recomendado' => 5000, 'minimo' => 2000],
    'max_execution_time' => ['recomendado' => 300, 'minimo' => 120],
    'max_input_time' => ['recomendado' => 300, 'minimo' => 120],
    'memory_limit' => ['recomendado' => '512M', 'minimo' => '256M']
];

foreach ($configs as $config => $valores) {
    $atual = ini_get($config);
    $status = '❌ Inadequado';
    
    if ($config == 'max_file_uploads' || $config == 'max_input_vars' || 
        $config == 'max_execution_time' || $config == 'max_input_time') {
        if ((int)$atual >= $valores['minimo']) $status = '⚠️ Aceitável';
        if ((int)$atual >= $valores['recomendado']) $status = '✅ Ótimo';
    } else {
        $atual_bytes = return_bytes($atual);
        $minimo_bytes = return_bytes($valores['minimo']);
        $recomendado_bytes = return_bytes($valores['recomendado']);
        
        if ($atual_bytes >= $minimo_bytes) $status = '⚠️ Aceitável';
        if ($atual_bytes >= $recomendado_bytes) $status = '✅ Ótimo';
    }
    
    echo "<tr>";
    echo "<td><strong>{$config}</strong></td>";
    echo "<td>{$atual}</td>";
    echo "<td>{$status}</td>";
    echo "</tr>";
}

echo "</table>";

// Informações adicionais
echo "<h3>Informações do Sistema</h3>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";
echo "<p>Loaded php.ini: " . php_ini_loaded_file() . "</p>";

function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}
?>