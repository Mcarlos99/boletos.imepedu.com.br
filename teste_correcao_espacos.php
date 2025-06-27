<?php
/**
 * Teste da Correção de Acentos e Espaços - Breu Branco
 * Arquivo: teste_correcao_espacos.php (colocar na raiz)
 */

echo "<h2>🔧 Teste da Correção de Acentos e Espaços - Breu Branco</h2>";
echo "<pre>";

// Função para normalizar texto (mesma do MoodleAPI)
function normalizarTexto($texto) {
    // Remove espaços extras
    $texto = trim($texto);
    
    // Converte para minúsculas primeiro
    $texto = mb_strtolower($texto, 'UTF-8');
    
    // Remove acentos
    $acentos = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n'
    ];
    
    return str_replace(array_keys($acentos), array_values($acentos), $texto);
}

// Simula os nomes exatos que vêm do Moodle (com espaços e acentos)
$categoriasSimuladas = [
    ['id' => 1, 'name' => 'CURSOS TÉCNICOS ', 'parent' => 0], // ← Espaço no final + acento!
    ['id' => 26, 'name' => 'Técnico em Enfermagem ', 'parent' => 1], // ← Espaço no final + acento!
    ['id' => 27, 'name' => 'Técnico em Eletromecânica ', 'parent' => 1], // ← Espaço no final + acentos!
    ['id' => 28, 'name' => 'Técnico em Eletrotécnica ', 'parent' => 1], // ← Espaço no final + acentos!
    ['id' => 29, 'name' => 'Técnico em Segurança do Trabalho', 'parent' => 1], // Acentos
];

echo "📋 TESTANDO NORMALIZAÇÃO DE TEXTO:\n\n";

foreach ($categoriasSimuladas as $cat) {
    echo "ID: {$cat['id']}\n";
    echo "Nome original: '{$cat['name']}'\n";
    echo "Após trim: '" . trim($cat['name']) . "'\n";
    echo "Após normalização: '" . normalizarTexto($cat['name']) . "'\n";
    echo "\n";
}

echo "🔍 TESTANDO BUSCA DA CATEGORIA PAI COM NORMALIZAÇÃO:\n\n";

// Testa a busca da categoria pai
$categoriaCursosTecnicos = null;
foreach ($categoriasSimuladas as $category) {
    $nomeOriginal = trim($category['name']);
    $nomeCategoria = normalizarTexto($nomeOriginal);
    
    echo "Testando: '{$nomeOriginal}' -> '{$nomeCategoria}'\n";
    
    if (
        $nomeCategoria === 'cursos tecnicos' ||
        strpos($nomeCategoria, 'cursos tecnicos') !== false ||
        $nomeCategoria === 'tecnicos' ||
        ($category['id'] == 1 && strpos($nomeCategoria, 'tecnico') !== false)
    ) {
        $categoriaCursosTecnicos = $category;
        echo "✅ CATEGORIA PAI ENCONTRADA: '{$nomeOriginal}' (ID: {$category['id']})\n\n";
        break;
    } else {
        echo "❌ Não encontrou correspondência\n";
    }
}

if ($categoriaCursosTecnicos) {
    echo "🎯 BUSCANDO SUBCATEGORIAS:\n\n";
    
    $cursosEncontrados = [];
    foreach ($categoriasSimuladas as $category) {
        if ($category['parent'] == $categoriaCursosTecnicos['id']) {
            $nomeOriginal = trim($category['name']);
            $nomeNormalizado = normalizarTexto($nomeOriginal);
            
            echo "Subcategoria: '{$nomeOriginal}' -> '{$nomeNormalizado}'\n";
            
            // Testa validação
            $ehValido = false;
            if (strpos($nomeNormalizado, 'tecnico') !== false) {
                $palavrasChave = ['enfermagem', 'eletromecanica', 'eletrotecnica', 'seguranca', 'trabalho'];
                
                foreach ($palavrasChave as $palavra) {
                    if (strpos($nomeNormalizado, $palavra) !== false) {
                        $ehValido = true;
                        echo "✅ VÁLIDO: contém '{$palavra}'\n";
                        break;
                    }
                }
                
                if (!$ehValido) {
                    $ehValido = true; // Aceita se tem "tecnico" e passou pelos filtros
                    echo "✅ VÁLIDO: contém 'tecnico' e passou pelos filtros\n";
                }
            }
            
            if ($ehValido) {
                $cursosEncontrados[] = [
                    'id' => 'cat_' . $category['id'],
                    'nome' => $nomeOriginal, // Usa nome original limpo
                    'categoria_original_id' => $category['id']
                ];
            }
            echo "\n";
        }
    }
    
    echo "🏆 RESULTADO FINAL:\n";
    echo "Cursos técnicos encontrados: " . count($cursosEncontrados) . "\n\n";
    
    foreach ($cursosEncontrados as $curso) {
        echo "• {$curso['nome']} (ID: {$curso['categoria_original_id']})\n";
    }
    
    if (count($cursosEncontrados) === 4) {
        echo "\n✅ PERFEITO! Encontrou os 4 cursos técnicos esperados!\n";
    } else {
        echo "\n⚠️ Deveria encontrar 4 cursos técnicos.\n";
    }
    
} else {
    echo "❌ CATEGORIA PAI NÃO ENCONTRADA!\n";
    echo "Verifique se a normalização está funcionando corretamente.\n";
}

echo "\n";
echo "🧪 AGORA TESTE A API REAL:\n";
echo "URL: /admin/api/buscar-cursos.php?polo=breubranco.imepedu.com.br\n";
echo "\n";
echo "Se agora funcionar, o problema era realmente os acentos e espaços! ✅\n";

echo "</pre>";

// Teste adicional de normalização
echo "<h3>🔍 Teste Específico de Normalização:</h3>";
echo "<pre>";
$testesNormalizacao = [
    'CURSOS TÉCNICOS ',
    'Técnico em Enfermagem ',
    'Técnico em Eletromecânica ',
    'Técnico em Eletrotécnica ',
    'Técnico em Segurança do Trabalho'
];

foreach ($testesNormalizacao as $teste) {
    echo "'{$teste}' -> '" . normalizarTexto($teste) . "'\n";
}
echo "</pre>";
?>