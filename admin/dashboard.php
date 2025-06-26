// admin/dashboard.php
<?php
// Autenticação admin
session_start();
if (!isset($_SESSION['admin_logged'])) {
    header('Location: /admin/login');
    exit;
}

$totalBoletos = contarBoletos();
$boletosPendentes = contarBoletos('pendente');
$boletosPagos = contarBoletos('pago');
$valorTotal = somarValorBoletos();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin - Sistema de Boletos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <nav class="col-md-2 bg-dark sidebar">
                <div class="sidebar-sticky">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link text-white" href="/admin">Dashboard</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="/admin/boletos">Boletos</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="/admin/alunos">Alunos</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="/admin/baixas">Dar Baixas</a>
                        </li>
                    </ul>
                </div>
            </nav>
            
            <main class="col-md-10 ml-sm-auto px-4">
                <h1>Dashboard</h1>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <h5>Total de Boletos</h5>
                                <h2><?= $totalBoletos ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h5>Pendentes</h5>
                                <h2><?= $boletosPendentes ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h5>Pagos</h5>
                                <h2><?= $boletosPagos ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h5>Valor Total</h5>
                                <h2>R$ <?= number_format($valorTotal, 2, ',', '.') ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabela de boletos recentes -->
                <div class="mt-4">
                    <h3>Boletos Recentes</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Aluno</th>
                                <th>Curso</th>
                                <th>Valor</th>
                                <th>Vencimento</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $boletosRecentes = buscarBoletosRecentes(10); ?>
                            <?php foreach ($boletosRecentes as $boleto): ?>
                            <tr>
                                <td><?= $boleto['aluno_nome'] ?></td>
                                <td><?= $boleto['curso_nome'] ?></td>
                                <td>R$ <?= number_format($boleto['valor'], 2, ',', '.') ?></td>
                                <td><?= date('d/m/Y', strtotime($boleto['vencimento'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= $boleto['status'] == 'pago' ? 'success' : 'warning' ?>">
                                        <?= ucfirst($boleto['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($boleto['status'] == 'pendente'): ?>
                                        <button class="btn btn-sm btn-success" onclick="darBaixa(<?= $boleto['id'] ?>)">
                                            Dar Baixa
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>
    
    <script>
    function darBaixa(boletoId) {
        if (confirm('Confirma a baixa deste boleto?')) {
            fetch('/admin/baixa', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({boleto_id: boletoId})
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Erro ao dar baixa');
                }
            });
        }
    }
    </script>
</body>
</html>