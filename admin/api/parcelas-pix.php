<?php
/**
 * Arquivo: admin/api/parcelas-pix.php
 * API para gerenciar parcelas PIX
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once '../../config/database.php';
require_once '../../src/BoletoUploadService.php';

header('Content-Type: application/json');

try {
    $uploadService = new BoletoUploadService();
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            if (isset($_GET['action'])) {
                switch ($_GET['action']) {
                    case 'estatisticas':
                        $stats = $uploadService->getEstatisticasParcelasPix();
                        echo json_encode([
                            'success' => true,
                            'data' => $stats
                        ]);
                        break;
                        
                    case 'listar_pix':
                        $pagina = intval($_GET['pagina'] ?? 1);
                        $filtros = [];
                        
                        if (!empty($_GET['polo'])) {
                            $filtros['polo'] = $_GET['polo'];
                        }
                        
                        if (!empty($_GET['status'])) {
                            $filtros['status'] = $_GET['status'];
                        }
                        
                        if (!empty($_GET['com_desconto'])) {
                            $filtros['com_desconto_pix'] = ($_GET['com_desconto'] === 'true');
                        }
                        
                        $resultado = $uploadService->listarBoletosPix($filtros, $pagina, 20);
                        echo json_encode([
                            'success' => true,
                            'data' => $resultado
                        ]);
                        break;
                        
                    case 'parcelas_aluno':
                        if (empty($_GET['aluno_id'])) {
                            throw new Exception('ID do aluno é obrigatório');
                        }
                        
                        $parcelas = $uploadService->buscarParcelasAluno(
                            intval($_GET['aluno_id']),
                            !empty($_GET['curso_id']) ? intval($_GET['curso_id']) : null
                        );
                        
                        echo json_encode([
                            'success' => true,
                            'data' => $parcelas
                        ]);
                        break;
                        
                    case 'calcular_desconto':
                        if (empty($_GET['boleto_id'])) {
                            throw new Exception('ID do boleto é obrigatório');
                        }
                        
                        $desconto = $uploadService->calcularValorComDesconto(intval($_GET['boleto_id']));
                        echo json_encode([
                            'success' => true,
                            'data' => $desconto
                        ]);
                        break;
                        
                    default:
                        throw new Exception('Ação não reconhecida');
                }
            } else {
                throw new Exception('Ação não especificada');
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['action'])) {
                throw new Exception('Dados inválidos');
            }
            
            switch ($input['action']) {
                case 'marcar_desconto_usado':
                    if (empty($input['boleto_id'])) {
                        throw new Exception('ID do boleto é obrigatório');
                    }
                    
                    $sucesso = $uploadService->marcarDescontoPixUsado(intval($input['boleto_id']));
                    echo json_encode([
                        'success' => $sucesso,
                        'message' => $sucesso ? 'Desconto marcado como usado' : 'Não foi possível marcar desconto'
                    ]);
                    break;
                    
                default:
                    throw new Exception('Ação não reconhecida');
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Método não permitido']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>