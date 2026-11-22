<?php
$navActive = $navActive ?? '';
?>
<div class="navbar-nav flex-row flex-wrap gap-1" aria-label="Navegação principal">
  <a class="nav-link <?=$navActive==='overview'?'active':''?>" href="dashboard.php#overview">Painel</a>
  <a class="nav-link <?=$navActive==='forecasts'?'active':''?>" href="dashboard.php#forecasts">Previsões</a>
  <a class="nav-link <?=$navActive==='modules'?'active':''?>" href="dashboard.php#modules">Módulos</a>
  <a class="nav-link <?=$navActive==='performance'?'active':''?>" href="dashboard.php#performance">Desempenho</a>
  <a class="nav-link <?=$navActive==='paper'?'active':''?>" href="simulation.php">Paper trading</a>
  <a class="nav-link <?=$navActive==='settings'?'active':''?>" href="settings.php">Configurações</a>
</div>
