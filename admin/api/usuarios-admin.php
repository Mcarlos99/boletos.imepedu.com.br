<?php
/**
 * Sistema de Boletos IMEPEDU - APIs para Gerenciamento de Usuários Administrativos
 * Arquivo: admin/api/usuarios-admin.php
 */

session_start();

// Verifica autenticação
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

header('Content-Type: application/json');

require_once '../../config/database.php';

try {
    $acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
    
    switch ($acao) {
        case 'listar':
            echo json_encode(listarAdministradores());
            break;
            
        case 'obter':
            $id = $_GET['id'] ?? 0;
            echo json_encode(obterAdministrador($id));
            break;
            
        case 'adicionar':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(adicionarAdministrador($input));
            break;
            
        case 'editar':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(editarAdministrador($input));
            break;
            
        case 'alterar_senha':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(alterarSenha($input));
            break;
            
        case 'resetar_senha':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(resetarSenha($input));
            break;
            
        case 'toggle_status':
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode(toggleStatus($input));
            break;
            
        default:
            throw new Exception('Ação não reconhecida');
    }
    
} catch (Exception $e) {
    error_log("Erro na API de usuários admin: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Lista todos os administradores
 */
function listarAdministradores() {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            SELECT id, nome, login, nivel, email, ativo, created_at, ultimo_acesso
            FROM administradores 
            ORDER BY nome ASC
        ");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'admins' => $admins
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Erro ao listar administradores: ' . $e->getMessage()
        ];
    }
}

/**
 * Obtém dados de um administrador específico
 */
function obterAdministrador($id) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            SELECT id, nome, login, nivel, email, ativo, created_at, ultimo_acesso
            FROM administradores 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            throw new Exception('Administrador não encontrado');
        }
        
        return [
            'success' => true,
            'admin' => $admin
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Adiciona novo administrador
 */
function adicionarAdministrador($dados) {
    try {
        $db = (new Database())->getConnection();
        
        // Verifica se usuário atual é admin
        if (!verificarPermissaoAdmin()) {
            throw new Exception('Apenas administradores podem adicionar novos usuários');
        }
        
        $nome = trim($dados['nome'] ?? '');
        $login = trim($dados['login'] ?? '');
        $nivel = $dados['nivel'] ?? 'operador';
        $email = trim($dados['email'] ?? '');
        
        // Validações
        validarDadosAdmin($nome, $login, $nivel);
        
        // Verifica se login já existe
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM administradores WHERE login = ?");
        $stmt->execute([$login]);
        if ($stmt->fetch()['count'] > 0) {
            throw new Exception('Login já está em uso');
        }
        
        // Gera senha temporária
        $senhaTemporaria = gerarSenhaSegura();
        $senhaHash = password_hash($senhaTemporaria, PASSWORD_DEFAULT);
        
        // Insere novo administrador
        $stmt = $db->prepare("
            INSERT INTO administradores (nome, login, senha, nivel, email, ativo, created_at) 
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$nome, $login, $senhaHash, $nivel, $email]);
        
        $adminId = $db->lastInsertId();
        
        // Log da operação
        registrarLog('admin_criado', $adminId, "Novo administrador criado: {$nome} ({$login})");
        
        return [
            'success' => true,
            'message' => "Administrador {$nome} adicionado com sucesso!",
            'senha_temporaria' => $senhaTemporaria,
            'admin_id' => $adminId
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Edita administrador existente
 */
function editarAdministrador($dados) {
    try {
        $db = (new Database())->getConnection();
        
        $adminId = intval($dados['admin_id'] ?? 0);
        $nome = trim($dados['nome'] ?? '');
        $nivel = $dados['nivel'] ?? 'operador';
        $email = trim($dados['email'] ?? '');
        $ativo = intval($dados['ativo'] ?? 1);
        
        if (!$adminId) {
            throw new Exception('ID do administrador é obrigatório');
        }
        
        // Verifica se usuário atual pode editar
        if (!verificarPermissaoEdicao($adminId)) {
            throw new Exception('Você não tem permissão para editar este usuário');
        }
        
        // Validações básicas
        if (empty($nome)) {
            throw new Exception('Nome é obrigatório');
        }
        
        if (!in_array($nivel, ['admin', 'operador', 'visualizador'])) {
            throw new Exception('Nível de acesso inválido');
        }
        
        // Não permite desativar o próprio usuário
        if ($adminId == $_SESSION['admin_id'] && $ativo == 0) {
            throw new Exception('Você não pode desativar sua própria conta');
        }
        
        // Atualiza dados
        $stmt = $db->prepare("
            UPDATE administradores 
            SET nome = ?, nivel = ?, email = ?, ativo = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$nome, $nivel, $email, $ativo, $adminId]);
        
        // Log da operação
        registrarLog('admin_editado', $adminId, "Administrador editado: {$nome}");
        
        return [
            'success' => true,
            'message' => 'Administrador atualizado com sucesso!'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Altera senha do próprio usuário
 */
function alterarSenha($dados) {
    try {
        $db = (new Database())->getConnection();
        
        $senhaAtual = $dados['senha_atual'] ?? '';
        $senhaNova = $dados['senha_nova'] ?? '';
        $adminId = $_SESSION['admin_id'];
        
        // Validações
        if (empty($senhaAtual) || empty($senhaNova)) {
            throw new Exception('Senha atual e nova senha são obrigatórias');
        }
        
        if (strlen($senhaNova) < 6) {
            throw new Exception('Nova senha deve ter pelo menos 6 caracteres');
        }
        
        // Validação adicional de força da senha
        if (!validarForcaSenha($senhaNova)) {
            throw new Exception('Nova senha deve conter ao menos uma letra maiúscula, uma minúscula e um número');
        }
        
        // Verifica senha atual
        $stmt = $db->prepare("SELECT senha, nome FROM administradores WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        
        if (!$admin || !password_verify($senhaAtual, $admin['senha'])) {
            throw new Exception('Senha atual incorreta');
        }
        
        // Verifica se não está usando a mesma senha
        if (password_verify($senhaNova, $admin['senha'])) {
            throw new Exception('A nova senha deve ser diferente da atual');
        }
        
        // Atualiza senha
        $senhaNovaHash = password_hash($senhaNova, PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            UPDATE administradores 
            SET senha = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$senhaNovaHash, $adminId]);
        
        // Log da operação
        registrarLog('senha_alterada', $adminId, "Senha alterada pelo usuário {$admin['nome']}");
        
        return [
            'success' => true,
            'message' => 'Senha alterada com sucesso!'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Reseta senha de outro administrador
 */
function resetarSenha($dados) {
    try {
        $db = (new Database())->getConnection();
        
        $adminId = intval($dados['admin_id'] ?? 0);
        
        if (!$adminId) {
            throw new Exception('ID do administrador é obrigatório');
        }
        
        // Verifica permissão
        if (!verificarPermissaoAdmin()) {
            throw new Exception('Apenas administradores podem resetar senhas');
        }
        
        // Não permite resetar a própria senha
        if ($adminId == $_SESSION['admin_id']) {
            throw new Exception('Use a opção "Alterar Senha" para modificar sua própria senha');
        }
        
        // Busca dados do admin
        $stmt = $db->prepare("SELECT nome, login FROM administradores WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            throw new Exception('Administrador não encontrado');
        }
        
        // Gera nova senha temporária
        $novaSenha = gerarSenhaSegura();
        $senhaHash = password_hash($novaSenha, PASSWORD_DEFAULT);
        
        // Atualiza senha
        $stmt = $db->prepare("
            UPDATE administradores 
            SET senha = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$senhaHash, $adminId]);
        
        // Log da operação
        registrarLog('senha_resetada', $adminId, "Senha resetada para {$admin['nome']} pelo admin " . $_SESSION['admin_id']);
        
        return [
            'success' => true,
            'message' => 'Senha resetada com sucesso!',
            'login' => $admin['login'],
            'senha_temporaria' => $novaSenha
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Alterna status ativo/inativo
 */
function toggleStatus($dados) {
    try {
        $db = (new Database())->getConnection();
        
        $adminId = intval($dados['admin_id'] ?? 0);
        $ativo = intval($dados['ativo'] ?? 0);
        
        if (!$adminId) {
            throw new Exception('ID do administrador é obrigatório');
        }
        
        // Verifica permissão
        if (!verificarPermissaoAdmin()) {
            throw new Exception('Apenas administradores podem alterar status');
        }
        
        // Não permite desativar própria conta
        if ($adminId == $_SESSION['admin_id'] && $ativo == 0) {
            throw new Exception('Você não pode desativar sua própria conta');
        }
        
        // Busca dados do admin
        $stmt = $db->prepare("SELECT nome FROM administradores WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            throw new Exception('Administrador não encontrado');
        }
        
        // Atualiza status
        $stmt = $db->prepare("
            UPDATE administradores 
            SET ativo = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$ativo, $adminId]);
        
        $acao = $ativo ? 'ativado' : 'desativado';
        
        // Log da operação
        registrarLog('admin_status_alterado', $adminId, "Administrador {$admin['nome']} {$acao}");
        
        return [
            'success' => true,
            'message' => "Administrador {$acao} com sucesso!"
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Funções auxiliares
 */

function verificarPermissaoAdmin() {
    try {
        $db = (new Database())->getConnection();
        $stmt = $db->prepare("SELECT nivel FROM administradores WHERE id = ? AND ativo = 1");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        
        return $admin && $admin['nivel'] === 'admin';
    } catch (Exception $e) {
        return false;
    }
}

function verificarPermissaoEdicao($adminIdTarget) {
    // Admins podem editar qualquer um
    if (verificarPermissaoAdmin()) {
        return true;
    }
    
    // Usuários podem editar apenas a si mesmos (dados básicos)
    return $adminIdTarget == $_SESSION['admin_id'];
}

function validarDadosAdmin($nome, $login, $nivel) {
    if (empty($nome)) {
        throw new Exception('Nome é obrigatório');
    }
    
    if (empty($login)) {
        throw new Exception('Login é obrigatório');
    }
    
    if (strlen($login) < 3) {
        throw new Exception('Login deve ter pelo menos 3 caracteres');
    }
    
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
        throw new Exception('Login deve conter apenas letras, números e underscore');
    }
    
    if (!in_array($nivel, ['admin', 'operador', 'visualizador'])) {
        throw new Exception('Nível de acesso inválido');
    }
}

function validarForcaSenha($senha) {
    // Pelo menos 6 caracteres, uma maiúscula, uma minúscula e um número
    if (strlen($senha) < 6) return false;
    if (!preg_match('/[A-Z]/', $senha)) return false;
    if (!preg_match('/[a-z]/', $senha)) return false;
    if (!preg_match('/[0-9]/', $senha)) return false;
    
    return true;
}

function gerarSenhaSegura($tamanho = 10) {
    $maiusculas = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $minusculas = 'abcdefghijklmnopqrstuvwxyz';
    $numeros = '0123456789';
    $simbolos = '!@#$%*';
    
    $senha = '';
    
    // Garante pelo menos um de cada tipo
    $senha .= $maiusculas[random_int(0, strlen($maiusculas) - 1)];
    $senha .= $minusculas[random_int(0, strlen($minusculas) - 1)];
    $senha .= $numeros[random_int(0, strlen($numeros) - 1)];
    $senha .= $simbolos[random_int(0, strlen($simbolos) - 1)];
    
    // Completa o restante aleatoriamente
    $todosCaracteres = $maiusculas . $minusculas . $numeros . $simbolos;
    for ($i = 4; $i < $tamanho; $i++) {
        $senha .= $todosCaracteres[random_int(0, strlen($todosCaracteres) - 1)];
    }
    
    // Embaralha a senha
    return str_shuffle($senha);
}

function registrarLog($tipo, $adminId, $descricao) {
    try {
        $db = (new Database())->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO logs (tipo, usuario_id, descricao, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $tipo,
            $adminId,
            $descricao,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
    } catch (Exception $e) {
        error_log("Erro ao registrar log: " . $e->getMessage());
    }
}
?>