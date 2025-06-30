<?php
/**
 * Sistema de Boletos IMEPEDU - Gerenciamento de Usuários Administrativos
 * Arquivo: admin/usuarios.php
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../config/moodle.php';
require_once '../src/AdminService.php';
require_once '../src/UsuarioAdminService.php';
require_once 'includes/verificar-permissao.php';

$adminService = new AdminService();
$usuarioService = new UsuarioAdminService();

// Verifica permissões
$adminAtual = $adminService->buscarAdminPorId($_SESSION['admin_id']);
if (!$usuarioService->podeGerenciarUsuarios($adminAtual)) {
    header('Location: /admin/dashboard.php');
    exit;
}

// Processa ações
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['acao'])) {
            switch ($_POST['acao']) {
                case 'criar':
                    $resultado = $usuarioService->criarUsuario($_POST);
                    $mensagem = "Usuário criado com sucesso!";
                    break;
                    
                case 'editar':
                    $resultado = $usuarioService->editarUsuario($_POST);
                    $mensagem = "Usuário atualizado com sucesso!";
                    break;
                    
                case 'excluir':
                    $resultado = $usuarioService->excluirUsuario($_POST['usuario_id']);
                    $mensagem = "Usuário excluído com sucesso!";
                    break;
                    
                case 'resetar_senha':
                    $novaSenha = $usuarioService->resetarSenha($_POST['usuario_id']);
                    $mensagem = "Senha resetada com sucesso! Nova senha: {$novaSenha}";
                    break;
            }
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Busca usuários
$filtros = [];
if (!empty($_GET['polo'])) $filtros['polo'] = $_GET['polo'];
if (!empty($_GET['nivel'])) $filtros['nivel'] = $_GET['nivel'];
if (!empty($_GET['busca'])) $filtros['busca'] = $_GET['busca'];

$usuarios = $usuarioService->listarUsuarios($filtros, $adminAtual);
$polosAtivos = MoodleConfig::getActiveSubdomains();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Administração IMEPEDU</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #0066cc;
            --secondary-color: #004499;
            --sidebar-width: 260px;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-color), var(--secondary-color));
            color: white;
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        
        .sidebar-brand {
            padding: 1.5rem 1rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .nav-item {
            margin: 0.25rem 0;
        }
        
        .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .user-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .user-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .nivel-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .nivel-super_admin { background: rgba(220,53,69,0.1); color: #dc3545; }
        .nivel-admin_polo { background: rgba(0,123,255,0.1); color: #007bff; }
        .nivel-visualizador { background: rgba(108,117,125,0.1); color: #6c757d; }
        
        .permissao-item {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            margin: 0.25rem;
            background: rgba(0,102,204,0.1);
            border-radius: 15px;
            font-size: 0.8rem;
        }
        
        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <h4><i class="fas fa-graduation-cap"></i> IMEPEDU Admin</h4>
            <small>Sistema de Boletos</small>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-item">
                <a href="/admin/dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
            </div>
            <?php if ($adminAtual['nivel_acesso'] === 'super_admin'): ?>
            <div class="nav-item">
                <a href="/admin/usuarios.php" class="nav-link active">
                    <i class="fas fa-users-cog"></i>
                    Usuários Admin
                </a>
            </div>
            <?php endif; ?>
            <div class="nav-item">
                <a href="/admin/boletos.php" class="nav-link">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Gerenciar Boletos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/upload-boletos.php" class="nav-link">
                    <i class="fas fa-upload"></i>
                    Upload de Boletos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/alunos.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    Alunos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/cursos.php" class="nav-link">
                    <i class="fas fa-book"></i>
                    Cursos
                </a>
            </div>
            <div class="nav-item">
                <a href="/admin/relatorios.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i>
                    Relatórios
                </a>
            </div>
            
            <hr style="border-color: rgba(255,255,255,0.1); margin: 1rem 0;">
            
            <div class="nav-item">
                <a href="/admin/logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Sair
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3>Gerenciar Usuários Administrativos</h3>
                <small class="text-muted">Controle de acesso ao sistema</small>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#criarUsuarioModal">
                <i class="fas fa-plus"></i> Novo Usuário
            </button>
        </div>
        
        <!-- Alertas -->
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($mensagem) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($erro) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Polo</label>
                        <select name="polo" class="form-select">
                            <option value="">Todos os polos</option>
                            <?php foreach ($polosAtivos as $polo): ?>
                                <?php $config = MoodleConfig::getConfig($polo); ?>
                                <option value="<?= $polo ?>" <?= ($_GET['polo'] ?? '') == $polo ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Nível de Acesso</label>
                        <select name="nivel" class="form-select">
                            <option value="">Todos os níveis</option>
                            <option value="super_admin" <?= ($_GET['nivel'] ?? '') == 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
                            <option value="admin_polo" <?= ($_GET['nivel'] ?? '') == 'admin_polo' ? 'selected' : '' ?>>Admin de Polo</option>
                            <option value="visualizador" <?= ($_GET['nivel'] ?? '') == 'visualizador' ? 'selected' : '' ?>>Visualizador</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Nome, email, login..." 
                               value="<?= htmlspecialchars($_GET['busca'] ?? '') ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary d-block w-100">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Usuários -->
        <div class="row">
            <?php foreach ($usuarios as $usuario): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card user-card" onclick="verDetalhesUsuario(<?= $usuario['id'] ?>)">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="user-avatar me-3">
                                    <?= strtoupper(substr($usuario['nome'], 0, 1)) ?>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="mb-1"><?= htmlspecialchars($usuario['nome']) ?></h5>
                                    <p class="text-muted mb-0">
                                        <small>
                                            <i class="fas fa-user"></i> <?= htmlspecialchars($usuario['login']) ?>
                                        </small>
                                    </p>
                                </div>
                                <span class="nivel-badge nivel-<?= $usuario['nivel_acesso'] ?>">
                                    <?= $usuarioService->getNivelLabel($usuario['nivel_acesso']) ?>
                                </span>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted d-block">
                                    <i class="fas fa-envelope"></i> <?= htmlspecialchars($usuario['email']) ?>
                                </small>
                                <?php if ($usuario['polo_restrito']): ?>
                                    <?php $configPolo = MoodleConfig::getConfig($usuario['polo_restrito']); ?>
                                    <small class="text-muted d-block">
                                        <i class="fas fa-map-marker-alt"></i> 
                                        <?= htmlspecialchars($configPolo['name'] ?? $usuario['polo_restrito']) ?>
                                    </small>
                                <?php else: ?>
                                    <small class="text-muted d-block">
                                        <i class="fas fa-globe"></i> Acesso a todos os polos
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($usuario['permissoes']): ?>
                                <div class="mb-3">
                                    <?php $permissoes = json_decode($usuario['permissoes'], true); ?>
                                    <?php foreach ($permissoes as $permissao => $valor): ?>
                                        <?php if ($valor): ?>
                                            <span class="permissao-item">
                                                <i class="fas fa-check"></i> 
                                                <?= $usuarioService->getPermissaoLabel($permissao) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    <?php if ($usuario['ultimo_acesso']): ?>
                                        Último acesso: <?= date('d/m/Y H:i', strtotime($usuario['ultimo_acesso'])) ?>
                                    <?php else: ?>
                                        Nunca acessou
                                    <?php endif; ?>
                                </small>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="editarUsuario(event, <?= $usuario['id'] ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($usuario['id'] != $_SESSION['admin_id']): ?>
                                        <button class="btn btn-outline-warning" onclick="resetarSenha(event, <?= $usuario['id'] ?>)">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="excluirUsuario(event, <?= $usuario['id'] ?>)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (empty($usuarios)): ?>
            <div class="text-center py-5">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h5>Nenhum usuário encontrado</h5>
                <p class="text-muted">Tente ajustar os filtros ou crie um novo usuário</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Criar Usuário -->
    <div class="modal fade" id="criarUsuarioModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Criar Novo Usuário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="criarUsuarioForm">
                    <input type="hidden" name="acao" value="criar">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Nome Completo</label>
                                    <input type="text" class="form-control" name="nome" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" name="email" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Login</label>
                                    <input type="text" class="form-control" name="login" required>
                                    <small class="form-text text-muted">Usado para acessar o sistema</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Senha</label>
                                    <input type="password" class="form-control" name="senha" required minlength="6">
                                    <small class="form-text text-muted">Mínimo 6 caracteres</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Nível de Acesso</label>
                                    <select class="form-select" name="nivel_acesso" id="nivelAcesso" required>
                                        <option value="">Selecione...</option>
                                        <?php if ($adminAtual['nivel_acesso'] === 'super_admin'): ?>
                                            <option value="super_admin">Super Admin (Acesso Total)</option>
                                        <?php endif; ?>
                                        <option value="admin_polo">Admin de Polo</option>
                                        <option value="visualizador">Visualizador</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3" id="poloField" style="display: none;">
                                    <label class="form-label">Polo Restrito</label>
                                    <select class="form-select" name="polo_restrito">
                                        <option value="">Selecione o polo...</option>
                                        <?php foreach ($polosAtivos as $polo): ?>
                                            <?php $config = MoodleConfig::getConfig($polo); ?>
                                            <option value="<?= $polo ?>">
                                                <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div id="permissoesField" style="display: none;">
                            <h6 class="mb-3">Permissões Específicas</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="permissoes[ver_boletos]" value="1" id="verBoletos">
                                        <label class="form-check-label" for="verBoletos">
                                            Ver Boletos
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="permissoes[criar_boletos]" value="1" id="criarBoletos">
                                        <label class="form-check-label" for="criarBoletos">
                                            Criar Boletos
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="permissoes[editar_boletos]" value="1" id="editarBoletos">
                                        <label class="form-check-label" for="editarBoletos">
                                            Editar Boletos
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="permissoes[ver_alunos]" value="1" id="verAlunos">
                                        <label class="form-check-label" for="verAlunos">
                                            Ver Alunos
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="permissoes[editar_alunos]" value="1" id="editarAlunos">
                                        <label class="form-check-label" for="editarAlunos">
                                            Editar Alunos
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="permissoes[sincronizar]" value="1" id="sincronizar">
                                        <label class="form-check-label" for="sincronizar">
                                            Sincronizar Moodle
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="permissoes[ver_relatorios]" value="1" id="verRelatorios">
                                        <label class="form-check-label" for="verRelatorios">
                                            Ver Relatórios
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="permissoes[exportar_dados]" value="1" id="exportarDados">
                                        <label class="form-check-label" for="exportarDados">
                                            Exportar Dados
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="permissoes[ver_logs]" value="1" id="verLogs">
                                        <label class="form-check-label" for="verLogs">
                                            Ver Logs
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Criar Usuário
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Usuário -->
    <div class="modal fade" id="editarUsuarioModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Usuário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editarUsuarioForm">
                    <input type="hidden" name="acao" value="editar">
                    <input type="hidden" name="usuario_id" id="edit_usuario_id">
                    <div class="modal-body" id="editarUsuarioConteudo">
                        <!-- Conteúdo será preenchido via JavaScript -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        // Controle de exibição de campos baseado no nível de acesso
        document.getElementById('nivelAcesso').addEventListener('change', function() {
            const nivel = this.value;
            const poloField = document.getElementById('poloField');
            const permissoesField = document.getElementById('permissoesField');
            
            if (nivel === 'admin_polo' || nivel === 'visualizador') {
                poloField.style.display = 'block';
                permissoesField.style.display = 'block';
                
                // Se for admin_polo, marca algumas permissões por padrão
                if (nivel === 'admin_polo') {
                    document.getElementById('verBoletos').checked = true;
                    document.getElementById('criarBoletos').checked = true;
                    document.getElementById('verAlunos').checked = true;
                    document.getElementById('verRelatorios').checked = true;
                }
                // Se for visualizador, marca apenas visualização
                else if (nivel === 'visualizador') {
                    document.getElementById('verBoletos').checked = true;
                    document.getElementById('verAlunos').checked = false;
                    document.getElementById('verRelatorios').checked = true;
                    // Desmarca todas as ações de edição
                    document.getElementById('criarBoletos').checked = false;
                    document.getElementById('editarBoletos').checked = false;
                    document.getElementById('editarAlunos').checked = false;
                    document.getElementById('sincronizar').checked = false;
                    document.getElementById('exportarDados').checked = false;
                }
            } else {
                poloField.style.display = 'none';
                permissoesField.style.display = 'none';
            }
        });
        
        // Ver detalhes do usuário
        function verDetalhesUsuario(usuarioId) {
            // Por enquanto, abre edição
            editarUsuario(event, usuarioId);
        }
        
        // Editar usuário
        function editarUsuario(event, usuarioId) {
            event.stopPropagation();
            
            // Busca dados do usuário via AJAX
            fetch(`/admin/api/buscar-usuario.php?id=${usuarioId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        preencherFormularioEdicao(data.usuario);
                        const modal = new bootstrap.Modal(document.getElementById('editarUsuarioModal'));
                        modal.show();
                    } else {
                        alert('Erro ao buscar dados do usuário');
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao buscar dados do usuário');
                });
        }
        
        // Preenche formulário de edição
        function preencherFormularioEdicao(usuario) {
            document.getElementById('edit_usuario_id').value = usuario.id;
            
            const permissoes = usuario.permissoes ? JSON.parse(usuario.permissoes) : {};
            
            const html = `
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Nome Completo</label>
                            <input type="text" class="form-control" name="nome" value="${usuario.nome}" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="${usuario.email}" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Login</label>
                            <input type="text" class="form-control" value="${usuario.login}" readonly>
                            <small class="form-text text-muted">Login não pode ser alterado</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Nova Senha (opcional)</label>
                            <input type="password" class="form-control" name="nova_senha" minlength="6">
                            <small class="form-text text-muted">Deixe em branco para manter a senha atual</small>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">Nível de Acesso</label>
                            <select class="form-select" name="nivel_acesso" id="edit_nivelAcesso" required>
                                ${usuario.nivel_acesso === 'super_admin' ? '<option value="super_admin" selected>Super Admin</option>' : ''}
                                <option value="admin_polo" ${usuario.nivel_acesso === 'admin_polo' ? 'selected' : ''}>Admin de Polo</option>
                                <option value="visualizador" ${usuario.nivel_acesso === 'visualizador' ? 'selected' : ''}>Visualizador</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3" id="edit_poloField" style="${usuario.nivel_acesso !== 'super_admin' ? '' : 'display: none;'}">
                            <label class="form-label">Polo Restrito</label>
                            <select class="form-select" name="polo_restrito">
                                <option value="">Selecione o polo...</option>
                                <?php foreach ($polosAtivos as $polo): ?>
                                    <?php $config = MoodleConfig::getConfig($polo); ?>
                                    <option value="<?= $polo ?>" ${usuario.polo_restrito === '<?= $polo ?>' ? 'selected' : ''}>
                                        <?= htmlspecialchars($config['name'] ?? $polo) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="ativo" value="1" id="edit_ativo" ${usuario.ativo == 1 ? 'checked' : ''}>
                    <label class="form-check-label" for="edit_ativo">
                        Usuário Ativo
                    </label>
                </div>
                
                <div id="edit_permissoesField" style="${usuario.nivel_acesso !== 'super_admin' ? '' : 'display: none;'}">
                    <h6 class="mb-3">Permissões Específicas</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="permissoes[ver_boletos]" value="1" ${permissoes.ver_boletos ? 'checked' : ''}>
                                <label class="form-check-label">Ver Boletos</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="permissoes[criar_boletos]" value="1" ${permissoes.criar_boletos ? 'checked' : ''}>
                                <label class="form-check-label">Criar Boletos</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="permissoes[editar_boletos]" value="1" ${permissoes.editar_boletos ? 'checked' : ''}>
                                <label class="form-check-label">Editar Boletos</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="permissoes[ver_alunos]" value="1" ${permissoes.ver_alunos ? 'checked' : ''}>
                                <label class="form-check-label">Ver Alunos</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="permissoes[editar_alunos]" value="1" ${permissoes.editar_alunos ? 'checked' : ''}>
                                <label class="form-check-label">Editar Alunos</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="permissoes[sincronizar]" value="1" ${permissoes.sincronizar ? 'checked' : ''}>
                                <label class="form-check-label">Sincronizar Moodle</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="permissoes[ver_relatorios]" value="1" ${permissoes.ver_relatorios ? 'checked' : ''}>
                                <label class="form-check-label">Ver Relatórios</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="permissoes[exportar_dados]" value="1" ${permissoes.exportar_dados ? 'checked' : ''}>
                                <label class="form-check-label">Exportar Dados</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="permissoes[ver_logs]" value="1" ${permissoes.ver_logs ? 'checked' : ''}>
                                <label class="form-check-label">Ver Logs</label>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('editarUsuarioConteudo').innerHTML = html;
            
            // Adiciona listener para o select de nível
            document.getElementById('edit_nivelAcesso').addEventListener('change', function() {
                const nivel = this.value;
                const poloField = document.getElementById('edit_poloField');
                const permissoesField = document.getElementById('edit_permissoesField');
                
                if (nivel === 'admin_polo' || nivel === 'visualizador') {
                    poloField.style.display = 'block';
                    permissoesField.style.display = 'block';
                } else {
                    poloField.style.display = 'none';
                    permissoesField.style.display = 'none';
                }
            });
        }
        
        // Resetar senha
        function resetarSenha(event, usuarioId) {
            event.stopPropagation();
            
            if (confirm('Deseja realmente resetar a senha deste usuário? Uma nova senha será gerada.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="acao" value="resetar_senha">
                    <input type="hidden" name="usuario_id" value="${usuarioId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Excluir usuário
        function excluirUsuario(event, usuarioId) {
            event.stopPropagation();
            
            if (confirm('Deseja realmente excluir este usuário? Esta ação não pode ser desfeita.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="acao" value="excluir">
                    <input type="hidden" name="usuario_id" value="${usuarioId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>