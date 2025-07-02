<?php
/**
 * Script de Teste - Verificação do Desconto PIX
 * Execute este script para testar se o desconto está funcionando
 */

// Configuração para exibir erros
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';

echo "<h2>🔍 Teste de Desconto PIX - Sistema IMEPEDU</h2>";
echo "<hr>";

try {
    $db = (new Database())->getConnection();
    
    // 1. Verificar estrutura da tabela boletos
    echo "<h3>1. 📋 Verificando estrutura da tabela boletos</h3>";
    
    $stmt = $db->query("DESCRIBE boletos");
    $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $colunasDesconto = [
        'pix_desconto_disponivel',
        'pix_desconto_usado', 
        'pix_valor_desconto',
        'pix_valor_minimo'
    ];
    
    $colunasEncontradas = [];
    foreach ($colunas as $coluna) {
        if (in_array($coluna['Field'], $colunasDesconto)) {
            $colunasEncontradas[] = $coluna['Field'];
            echo "✅ Coluna encontrada: <strong>{$coluna['Field']}</strong> ({$coluna['Type']})<br>";
        }
    }
    
    $colunasFaltando = array_diff($colunasDesconto, $colunasEncontradas);
    if (!empty($colunasFaltando)) {
        echo "❌ <strong>Colunas faltando:</strong> " . implode(', ', $colunasFaltando) . "<br>";
        echo "<strong>Execute o script SQL de atualização primeiro!</strong><br>";
    } else {
        echo "✅ <strong>Todas as colunas de desconto PIX estão presentes!</strong><br>";
    }
    
    echo "<hr>";
    
    // 2. Buscar boletos com desconto habilitado
    echo "<h3>2. 🎁 Boletos com desconto PIX habilitado</h3>";
    
    $stmt = $db->query("
        SELECT 
            b.id,
            b.numero_boleto,
            b.valor,
            b.vencimento,
            b.status,
            b.pix_desconto_disponivel,
            b.pix_desconto_usado,
            b.pix_valor_desconto,
            b.pix_valor_minimo,
            a.nome as aluno_nome,
            c.nome as curso_nome,
            c.subdomain
        FROM boletos b
        INNER JOIN alunos a ON b.aluno_id = a.id  
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.pix_desconto_disponivel = 1
        ORDER BY b.created_at DESC
        LIMIT 10
    ");
    
    $boletosComDesconto = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($boletosComDesconto)) {
        echo "⚠️ <strong>Nenhum boleto com desconto PIX encontrado.</strong><br>";
        echo "Crie um boleto com desconto pelo painel administrativo primeiro.<br>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>ID</th><th>Número</th><th>Aluno</th><th>Valor Original</th><th>Desconto</th><th>Valor Final</th><th>Status</th><th>Teste</th>";
        echo "</tr>";
        
        foreach ($boletosComDesconto as $boleto) {
            $valorOriginal = (float)$boleto['valor'];
            $valorDesconto = (float)($boleto['pix_valor_desconto'] ?? 0);
            $valorFinal = $valorOriginal - $valorDesconto;
            
            // Garantir valor mínimo de R$ 10,00
            if ($valorFinal < 10.00) {
                $valorDesconto = $valorOriginal - 10.00;
                $valorFinal = 10.00;
            }
            
            $podeUsarDesconto = (
                $boleto['pix_desconto_disponivel'] == 1 &&
                $boleto['pix_desconto_usado'] == 0 &&
                $boleto['status'] != 'pago' &&
                strtotime($boleto['vencimento']) >= strtotime(date('Y-m-d')) &&
                $valorDesconto > 0
            );
            
            $corLinha = $podeUsarDesconto ? '#e8f5e8' : '#ffe8e8';
            
            echo "<tr style='background: {$corLinha};'>";
            echo "<td>{$boleto['id']}</td>";
            echo "<td>{$boleto['numero_boleto']}</td>";
            echo "<td>{$boleto['aluno_nome']}</td>";
            echo "<td>R$ " . number_format($valorOriginal, 2, ',', '.') . "</td>";
            echo "<td>R$ " . number_format($valorDesconto, 2, ',', '.') . "</td>";
            echo "<td><strong>R$ " . number_format($valorFinal, 2, ',', '.') . "</strong></td>";
            echo "<td>{$boleto['status']}</td>";
            echo "<td>";
            
            if ($podeUsarDesconto) {
                echo "✅ <a href='#' onclick='testarPIX({$boleto['id']})'>Testar PIX</a>";
            } else {
                if ($boleto['pix_desconto_usado'] == 1) {
                    echo "❌ Desconto já usado";
                } elseif ($boleto['status'] == 'pago') {
                    echo "❌ Boleto pago";
                } elseif (strtotime($boleto['vencimento']) < strtotime(date('Y-m-d'))) {
                    echo "❌ Vencido";
                } else {
                    echo "❌ Sem desconto";
                }
            }
            
            echo "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
    
    echo "<hr>";
    
    // 3. Estatísticas gerais
    echo "<h3>3. 📊 Estatísticas</h3>";
    
    $stats = [
        'Total de boletos' => "SELECT COUNT(*) FROM boletos",
        'Boletos com desconto PIX' => "SELECT COUNT(*) FROM boletos WHERE pix_desconto_disponivel = 1",
        'Descontos PIX usados' => "SELECT COUNT(*) FROM boletos WHERE pix_desconto_usado = 1", 
        'PIX códigos gerados' => "SELECT COUNT(*) FROM pix_gerados",
        'PIX com desconto aplicado' => "SELECT COUNT(*) FROM pix_gerados WHERE tem_desconto = 1"
    ];
    
    foreach ($stats as $descricao => $query) {
        try {
            $stmt = $db->query($query);
            $count = $stmt->fetchColumn();
            echo "📈 <strong>{$descricao}:</strong> {$count}<br>";
        } catch (Exception $e) {
            echo "❌ <strong>{$descricao}:</strong> Erro - {$e->getMessage()}<br>";
        }
    }
    
    echo "<hr>";
    
    // 4. Teste da função de cálculo de desconto
    echo "<h3>4. 🧮 Teste da Função de Cálculo</h3>";
    
    if (!empty($boletosComDesconto)) {
        $boleteTeste = $boletosComDesconto[0];
        
        echo "<strong>Testando boleto ID: {$boleteTeste['id']}</strong><br>";
        echo "• Valor original: R$ " . number_format($boleteTeste['valor'], 2, ',', '.') . "<br>";
        echo "• Desconto configurado: R$ " . number_format($boleteTeste['pix_valor_desconto'] ?? 0, 2, ',', '.') . "<br>";
        echo "• Valor mínimo: R$ " . number_format($boleteTeste['pix_valor_minimo'] ?? 0, 2, ',', '.') . "<br>";
        echo "• Vencimento: " . date('d/m/Y', strtotime($boleteTeste['vencimento'])) . "<br>";
        echo "• Status: {$boleteTeste['status']}<br>";
        echo "• Desconto disponível: " . ($boleteTeste['pix_desconto_disponivel'] ? 'SIM' : 'NÃO') . "<br>";
        echo "• Desconto usado: " . ($boleteTeste['pix_desconto_usado'] ? 'SIM' : 'NÃO') . "<br>";
        
        // Simular cálculo
        $valorOriginal = (float)$boleteTeste['valor'];
        $valorDesconto = (float)($boleteTeste['pix_valor_desconto'] ?? 0);
        $valorMinimo = (float)($boleteTeste['pix_valor_minimo'] ?? 0);
        
        $podeAplicarDesconto = (
            $boleteTeste['pix_desconto_disponivel'] == 1 &&
            $boleteTeste['pix_desconto_usado'] == 0 &&
            $boleteTeste['status'] != 'pago' &&
            strtotime($boleteTeste['vencimento']) >= strtotime(date('Y-m-d')) &&
            $valorDesconto > 0 &&
            ($valorMinimo == 0 || $valorOriginal >= $valorMinimo)
        );
        
        if ($podeAplicarDesconto) {
            $valorFinal = $valorOriginal - $valorDesconto;
            if ($valorFinal < 10.00) {
                $valorDesconto = $valorOriginal - 10.00;
                $valorFinal = 10.00;
            }
            
            echo "<div style='background: #e8f5e8; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "✅ <strong>DESCONTO SERÁ APLICADO!</strong><br>";
            echo "• Valor com desconto: <strong>R$ " . number_format($valorFinal, 2, ',', '.') . "</strong><br>";
            echo "• Economia: <strong>R$ " . number_format($valorDesconto, 2, ',', '.') . "</strong>";
            echo "</div>";
        } else {
            echo "<div style='background: #ffe8e8; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "❌ <strong>DESCONTO NÃO SERÁ APLICADO</strong><br>";
            
            if (!$boleteTeste['pix_desconto_disponivel']) {
                echo "• Motivo: Desconto não habilitado";
            } elseif ($boleteTeste['pix_desconto_usado']) {
                echo "• Motivo: Desconto já foi usado";
            } elseif ($boleteTeste['status'] == 'pago') {
                echo "• Motivo: Boleto já foi pago";
            } elseif (strtotime($boleteTeste['vencimento']) < strtotime(date('Y-m-d'))) {
                echo "• Motivo: Boleto vencido";
            } elseif ($valorDesconto <= 0) {
                echo "• Motivo: Valor do desconto não configurado";
            } elseif ($valorMinimo > 0 && $valorOriginal < $valorMinimo) {
                echo "• Motivo: Valor do boleto menor que o mínimo exigido";
            } else {
                echo "• Motivo: Condições não atendidas";
            }
            
            echo "</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='background: #ffe8e8; padding: 15px; border-radius: 5px;'>";
    echo "❌ <strong>ERRO:</strong> " . $e->getMessage();
    echo "</div>";
}

?>

<script>
function testarPIX(boletoId) {
    if (!confirm('Deseja gerar um código PIX de teste para o boleto ID ' + boletoId + '?')) {
        return;
    }
    
    // Simular chamada da API
    fetch('/api/gerar-pix.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            boleto_id: boletoId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let msg = 'PIX gerado com sucesso!\n\n';
            msg += 'Boleto: #' + data.boleto.numero + '\n';
            msg += 'Valor original: ' + data.boleto.valor_original_formatado + '\n';
            msg += 'Valor final: ' + data.boleto.valor_final_formatado + '\n';
            
            if (data.desconto.tem_desconto) {
                msg += 'Desconto aplicado: ' + data.desconto.valor_desconto_formatado + '\n';
                msg += '✅ DESCONTO PIX FUNCIONANDO!';
            } else {
                msg += 'Motivo sem desconto: ' + data.desconto.motivo;
            }
            
            alert(msg);
        } else {
            alert('Erro: ' + data.message);
        }
    })
    .catch(error => {
        alert('Erro na requisição: ' + error.message);
    });
}
</script>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { margin: 10px 0; }
th, td { padding: 8px; text-align: left; }
th { background: #f0f0f0; }
.success { color: #28a745; }
.error { color: #dc3545; }
.warning { color: #ffc107; }
</style>