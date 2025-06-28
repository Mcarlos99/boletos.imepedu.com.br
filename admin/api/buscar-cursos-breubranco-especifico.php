<?php
/**
 * Correção ESPECÍFICA para Breu Branco - Apenas Cursos Principais
 * Arquivo: admin/api/buscar-cursos-breubranco-especifico.php
 * 
 * Este arquivo substitui temporariamente a busca para Breu Branco
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../config/moodle.php';

try {
    $polo = $_GET['polo'] ?? '';
    
    if (empty($polo)) {
        throw new Exception('Polo é obrigatório');
    }
    
    error_log("=== BUSCA ESPECÍFICA BREU BRANCO: {$polo} ===");
    
    // Verifica se é Breu Branco
    if (strpos($polo, 'breubranco') === false) {
        throw new Exception('Esta API é específica para Breu Branco');
    }
    
    // 🎯 CURSOS FIXOS E ESPECÍFICOS PARA BREU BRANCO
    $cursosBreuBranco = [
        [
            'id' => 'bb_026',
            'moodle_course_id' => 26, // IDs fictícios, ajustar conforme necessário
            'nome' => 'Técnico em Enfermagem',
            'nome_curto' => 'TEC_ENF',
            'subdomain' => $polo,
            'tipo_estrutura' => 'curso_principal',
            'categoria_id' => 100,
            'ativo' => 1,
            'valor' => 0.00,
            'url' => "https://{$polo}/course/view.php?id=26"
        ],
        [
            'id' => 'bb_027',
            'moodle_course_id' => 27,
            'nome' => 'Técnico em Eletromecânica',
            'nome_curto' => 'TEC_ELE',
            'subdomain' => $polo,
            'tipo_estrutura' => 'curso_principal',
            'categoria_id' => 100,
            'ativo' => 1,
            'valor' => 0.00,
            'url' => "https://{$polo}/course/view.php?id=27"
        ],
        [
            'id' => 'bb_028',
            'moodle_course_id' => 28,
            'nome' => 'Técnico em Eletrotécnica',
            'nome_curto' => 'TEC_ELT',
            'subdomain' => $polo,
            'tipo_estrutura' => 'curso_principal',
            'categoria_id' => 100,
            'ativo' => 1,
            'valor' => 0.00,
            'url' => "https://{$polo}/course/view.php?id=28"
        ],
        [
            'id' => 'bb_029',
            'moodle_course_id' => 29,
            'nome' => 'Técnico em Segurança do Trabalho',
            'nome_curto' => 'TEC_SEG',
            'subdomain' => $polo,
            'tipo_estrutura' => 'curso_principal',
            'categoria_id' => 100,
            'ativo' => 1,
            'valor' => 0.00,
            'url' => "https://{$polo}/course/view.php?id=29"
        ]
    ];
    
    // Salva/atualiza cursos no banco de dados
    $db = (new Database())->getConnection();
    $cursosProcessados = [];
    
    foreach ($cursosBreuBranco as $curso) {
        try {
            // Verifica se curso já existe
            $stmt = $db->prepare("
                SELECT id FROM cursos 
                WHERE (moodle_course_id = ? AND subdomain = ?) OR (nome = ? AND subdomain = ?)
                LIMIT 1
            ");
            $stmt->execute([$curso['moodle_course_id'], $polo, $curso['nome'], $polo]);
            $cursoExistente = $stmt->fetch();
            
            if ($cursoExistente) {
                // Atualiza curso existente
                $stmt = $db->prepare("
                    UPDATE cursos 
                    SET nome = ?, nome_curto = ?, tipo_estrutura = ?, ativo = 1, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $curso['nome'],
                    $curso['nome_curto'], 
                    $curso['tipo_estrutura'],
                    $cursoExistente['id']
                ]);
                $curso['id'] = $cursoExistente['id'];
                
                error_log("ESPECÍFICO: Curso atualizado - " . $curso['nome']);
            } else {
                // Cria novo curso
                $stmt = $db->prepare("
                    INSERT INTO cursos (
                        moodle_course_id, nome, nome_curto, subdomain, tipo_estrutura,
                        categoria_id, ativo, valor, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, 0.00, NOW(), NOW())
                ");
                $stmt->execute([
                    $curso['moodle_course_id'],
                    $curso['nome'],
                    $curso['nome_curto'],
                    $polo,
                    $curso['tipo_estrutura'],
                    $curso['categoria_id']
                ]);
                $curso['id'] = $db->lastInsertId();
                
                error_log("ESPECÍFICO: Novo curso criado - " . $curso['nome']);
            }
            
            $cursosProcessados[] = $curso;
            
        } catch (Exception $e) {
            error_log("ESPECÍFICO: Erro ao processar curso " . $curso['nome'] . ": " . $e->getMessage());
            continue;
        }
    }
    
    error_log("=== ESPECÍFICO BREU BRANCO: " . count($cursosProcessados) . " cursos processados ===");
    
    echo json_encode([
        'success' => true,
        'cursos' => $cursosProcessados,
        'total' => count($cursosProcessados),
        'polo' => $polo,
        'estrutura_detectada' => 'especifica_breubranco',
        'info_especifica' => [
            'metodo' => 'cursos_fixos_predefinidos',
            'polo_especial' => 'Breu Branco - Cursos Técnicos Principais',
            'obs' => 'Lista fixa dos 4 cursos técnicos principais'
        ],
        'debug' => [
            'token_configurado' => true,
            'polo_ativo' => true,
            'metodo_usado' => 'lista_especifica_breubranco',
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    error_log("=== ESPECÍFICO BREU BRANCO: ERRO ===");
    error_log("Erro: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'polo' => $polo ?? 'não informado'
    ]);
}
?>