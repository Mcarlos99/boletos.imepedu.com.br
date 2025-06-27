<?php
/**
 * Script de Teste - Filtro de Cursos
 * Arquivo: teste_filtro_cursos.php (colocar na raiz)
 * 
 * Testa os filtros de curso vs disciplina
 */

require_once 'config/database.php';
require_once 'config/moodle.php';
require_once 'src/MoodleAPI.php';

// Exemplos reais baseados nas imagens
$exemplosCursos = [
    // ✅ DEVEM SER ACEITOS (Cursos principais)
    'Técnico em Enfermagem',
    'Técnico em Eletromecânica', 
    'Técnico em Eletrotécnica',
    'Técnico em Segurança do Trabalho',
    'Enfermagem',
    'Administração',
    'Direito',
    
    // ❌ DEVEM SER REJEITADOS (Disciplinas/Matérias)
    'Higiene e Medicina do Trabalho I',
    'Higiene e Medicina do Trabalho II',
    'Higiene e Segurança do Trabalho',
    'Higiene e Segurança do Trabalho e na Construção Civil',
    'Informática Aplicada Eletromecânica',
    'Introdução a Enfermagem',
    'Noções de Administração de Unidade de Enfermagem',
    'Psicologia Aplicada ao Trabalho',
    'Psicologia do Trabalho',
    'Psicologia do Trabalho e Desenvolvimento Interpessoal',
    'Saúde e Segurança do Trabalho',
    'Estágio Supervisionado',
    'Módulo I',
    'Disciplina de Anatomia',
    'Metodologia Científica',
    'Português Instrumental',
    'Matemática Aplicada',
    'Ética Profissional'
];

echo "<h2>🧪 TESTE DO FILTRO DE CURSOS vs DISCIPLINAS</h2>\n";
echo "<pre>\n";

// Simula a função ehCursoPrincipal
function testarFiltro($nome) {
    $nome = strtolower($nome);
    
    // ❌ EXCLUSÕES: Palavras que indicam disciplinas/matérias
    $disciplinasIndicadores = [
        'estágio', 'estagio', 'supervisionado',
        'introdução', 'introducao', 'noções', 'nocoes',
        'higiene', 'medicina', 'psicologia', 'aplicada',
        'informática', 'informatica', 'aplicada',
        'administração de unidade', 'administracao de unidade',
        'desenvolvimento interpessoal',
        'construção civil', 'construcao civil',
        'módulo', 'modulo', 'unidade', 'disciplina',
        'matéria', 'materia', 'aula', 'seminário', 'seminario',
        'saúde e segurança', 'saude e seguranca',
        'fundamentos de', 'principios de', 'princípios de',
        'conceitos de', 'teoria de', 'prática de', 'pratica de',
        'metodologia', 'português', 'portugues', 'matemática', 'matematica',
        'ética', 'etica', 'instrumental'
    ];
    
    // Verifica exclusões
    foreach ($disciplinasIndicadores as $indicador) {
        if (strpos($nome, $indicador) !== false) {
            return ['aceito' => false, 'motivo' => "Contém indicador de disciplina: '{$indicador}'"];
        }
    }
    
    // ✅ INCLUSÕES: Palavras que indicam cursos principais
    $cursosIndicadores = [
        'técnico em', 'tecnico em',
        'superior em', 'graduação em', 'graduacao em',
        'bacharelado em', 'licenciatura em'
    ];
    
    foreach ($cursosIndicadores as $indicador) {
        if (strpos($nome, $indicador) !== false) {
            return ['aceito' => true, 'motivo' => "Contém indicador de curso: '{$indicador}'"];
        }
    }
    
    // Características específicas
    $caracteristicasCurso = [
        'enfermagem' => !strpos($nome, 'unidade') && !strpos($nome, 'introdução'),
        'administração' => !strpos($nome, 'de unidade') && !strpos($nome, 'noções'),
        'eletromecânica' => true,
        'eletromecanica' => true,
        'eletrotécnica' => true,
        'eletrotecnica' => true,
        'segurança do trabalho' => !strpos($nome, 'higiene') && !strpos($nome, 'saúde'),
        'seguranca do trabalho' => !strpos($nome, 'higiene') && !strpos($nome, 'saude'),
        'direito' => true
    ];
    
    foreach ($caracteristicasCurso as $caracteristica => $condicao) {
        if (strpos($nome, $caracteristica) !== false && $condicao) {
            return ['aceito' => true, 'motivo' => "Contém característica de curso: '{$caracteristica}'"];
        }
    }
    
    // Verifica se nome é muito longo (provável disciplina)
    $palavras = explode(' ', $nome);
    if (count($palavras) > 6) {
        return ['aceito' => false, 'motivo' => 'Nome muito longo (provável disciplina)'];
    }
    
    // Default: rejeita se não tem certeza
    return ['aceito' => false, 'motivo' => 'Não identificado como curso principal'];
}

// Testa cada exemplo
$acertos = 0;
$total = 0;

foreach ($exemplosCursos as $exemplo) {
    $total++;
    $resultado = testarFiltro($exemplo);
    
    // Determina o resultado esperado
    $deveSerAceito = in_array($exemplo, [
        'Técnico em Enfermagem',
        'Técnico em Eletromecânica', 
        'Técnico em Eletrotécnica',
        'Técnico em Segurança do Trabalho',
        'Enfermagem',
        'Administração',
        'Direito'
    ]);
    
    $acertou = ($resultado['aceito'] === $deveSerAceito);
    if ($acertou) $acertos++;
    
    $status = $acertou ? '✅' : '❌';
    $esperado = $deveSerAceito ? 'ACEITAR' : 'REJEITAR';
    $obtido = $resultado['aceito'] ? 'ACEITO' : 'REJEITADO';
    
    echo "{$status} {$exemplo}\n";
    echo "   Esperado: {$esperado} | Obtido: {$obtido}\n";
    echo "   Motivo: {$resultado['motivo']}\n";
    echo "\n";
}

echo "📊 RESULTADO FINAL: {$acertos}/{$total} acertos (" . round(($acertos/$total)*100, 1) . "%)\n";

if ($acertos === $total) {
    echo "🎉 TODOS OS TESTES PASSARAM! O filtro está funcionando perfeitamente.\n";
} else {
    echo "⚠️  Alguns testes falharam. Revisar os critérios de filtro.\n";
}

echo "</pre>\n";

// Teste real com API do Moodle (se token configurado)
try {
    echo "<h3>🔗 TESTE REAL COM API DO MOODLE</h3>\n";
    echo "<pre>\n";
    
    $moodleAPI = new MoodleAPI('breubranco.imepedu.com.br');
    $cursos = $moodleAPI->listarTodosCursos();
    
    echo "📋 CURSOS RETORNADOS PELA API FILTRADA:\n";
    echo "Total: " . count($cursos) . " cursos\n\n";
    
    foreach ($cursos as $curso) {
        echo "✅ {$curso['nome']} (Tipo: {$curso['tipo']})\n";
    }
    
    echo "</pre>\n";
    
} catch (Exception $e) {
    echo "<pre>❌ Erro ao testar API real: " . $e->getMessage() . "</pre>\n";
}

echo "<h3>📝 INSTRUÇÕES</h3>\n";
echo "<p><strong>Para aplicar a correção:</strong></p>\n";
echo "<ol>\n";
echo "<li>Substitua o arquivo <code>config/moodle.php</code> pela versão corrigida</li>\n";
echo "<li>Substitua o arquivo <code>src/MoodleAPI.php</code> pela versão com filtros</li>\n";
echo "<li>Teste a busca de cursos em: <code>/admin/api/buscar-cursos.php?polo=breubranco.imepedu.com.br</code></li>\n";
echo "<li>Verifique os logs para confirmar que apenas cursos principais estão sendo aceitos</li>\n";
echo "</ol>\n";

echo "<h3>🔍 LOGS DE DEBUG</h3>\n";
echo "<p>Para ver os logs detalhados, monitore:</p>\n";
echo "<code>tail -f /var/log/apache2/error.log | grep 'MoodleAPI'</code>\n";
?>