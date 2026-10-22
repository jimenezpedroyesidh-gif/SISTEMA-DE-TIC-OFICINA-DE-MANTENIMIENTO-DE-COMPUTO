<?php
/** Vista del listado de usuarios (public/usuarios.php). Solo presentación: recibe datos ya preparados por UsuariosPage. */
$paginaTitulo = 'Usuarios'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Usuarios del sistema'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome page-head">
      <div>
        <h1>Usuarios del sistema</h1>
        <p><?= count($usuarios) ?> cuenta(s) · crea cuentas, cambia roles y activa o desactiva el acceso de cada persona.</p>
      </div>
      <div class="page-acciones">
        <a href="register.php" class="btn btn-sm">+ Crear nuevo usuario</a>
      </div>
    </div>

  <div class="card mw-1100">
    <?php if($mensajePermiso): ?>
      <div class="mb-3 nota-info"><?= h($mensajePermiso) ?></div>
    <?php endif; ?>

    <div class="tabla-scroll">
    <table class="data">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Correo</th>
          <th>Rol</th>
          <th>Dependencia</th>
          <th>Estado</th>
          <th>Permisos</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usuarios as $u): ?>
        <tr id="u-<?= (int)$u['id'] ?>">
          <td data-label="Nombre"><?= h($u['nombre_completo']) ?><br><span class="hint">Código: <?= h($u['codigo']) ?: '<span class="texto-peligro">sin asignar</span>' ?></span></td>
          <td data-label="Correo"><?= h($u['correo']) ?></td>
          <td data-label="Rol"><?= h(etiquetaRol($u['role'])) ?><?php if ($u['role'] === Rol::BECA): ?><br><span class="chip <?= ($u['area_beca'] ?? '') === AreaBeca::SECRETARIA ? 'proceso' : 'espera' ?>"><?= h(AreaBeca::etiqueta($u['area_beca'] ?? null)) ?></span><?php endif; ?></td>
          <td data-label="Dependencia"><?= $u['dependencia'] ? h($u['dependencia']) : '<span class="text-muted">—</span>' ?></td>
          <td data-label="Estado"><?= $u['estado_activo'] ? '<span class="chip solucionado">Activa</span>' : '<span class="chip danio">Deshabilitada</span>' ?><?= isset($bloqueados[(int)$u['id']]) ? '<br>Bloqueada (3 intentos)' : '' ?><?= $u['password_temporal'] ? '<br><span class="hint">Clave temporal pendiente</span>' : '' ?><?php if (MfaService::aplica((string)$u['role'])): ?><br><span class="hint"><?= isset($conMfa[(int)$u['id']]) ? 'Con verificación en dos pasos' : 'Sin verificación en dos pasos' ?></span><?php endif; ?></td>
          <td data-label="Permisos">
            <?php if ($u['role'] === Rol::ADMIN): ?>
              <span class="text-muted">Todos (admin)</span>
            <?php else:
                $idU = (int)$u['id'];
                $permisosRol = UserPermissions::aplicablesA((string)$u['role']);
                $activosU = count(array_filter(array_keys($permisosRol), fn($k) => !empty($permisosUsuarios[$idU][$k])));
                $idPop = 'permisos-' . $idU; ?>
              <button type="button" class="btn btn-ghost btn-sm permisos-boton<?= $activosU ? ' con-activos' : '' ?>" popovertarget="<?= $idPop ?>">
                Permisos <span class="permisos-contador"><?= $activosU ?>/<?= count($permisosRol) ?></span>
              </button>
              <?php /* Menú flotante (popover nativo: queda encima de todo, no lo recorta la tabla y no necesita JS para abrir/cerrar). */ ?>
              <div class="permisos-flotante" id="<?= $idPop ?>" popover<?= $permisosAbierto === $idU ? ' data-popover-abrir' : '' ?>>
                <div class="permisos-flotante-head">
                  <div>
                    <strong><?= h($u['nombre_completo']) ?></strong>
                    <span class="hint d-block"><?= h(etiquetaRol((string)$u['role'])) ?> · <?= $activosU ?> de <?= count($permisosRol) ?> activo<?= count($permisosRol) === 1 ? '' : 's' ?></span>
                  </div>
                  <button type="button" class="permisos-cerrar" popovertarget="<?= $idPop ?>" popovertargetaction="hide" aria-label="Cerrar"><?= iconoSvg('cerrar') ?></button>
                </div>
                <?php foreach (UserPermissions::PERMISOS as $permisoKey => $defPermiso):
                    $aplica = isset($permisosRol[$permisoKey]);
                    $activo = $aplica && !empty($permisosUsuarios[$idU][$permisoKey]);
                    $idEtq = 'perm-' . $idU . '-' . $permisoKey; ?>
                  <form class="permiso-fila<?= $aplica ? '' : ' no-aplica' ?>" method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="alternar_permiso" value="1">
                    <input type="hidden" name="id_usuario" value="<?= $idU ?>">
                    <input type="hidden" name="permiso" value="<?= h($permisoKey) ?>">
                    <span class="permiso-etiqueta" id="<?= h($idEtq) ?>"><?= h($defPermiso['etiqueta']) ?>
                      <?php if (!$aplica): ?><span class="hint d-block">Solo para: <?= h(implode(', ', array_map(fn($r) => etiquetaRol($r), $defPermiso['roles']))) ?></span><?php endif; ?>
                    </span>
                    <button type="submit" class="switch<?= $activo ? ' is-on' : '' ?>" role="switch" aria-checked="<?= $activo ? 'true' : 'false' ?>" aria-labelledby="<?= h($idEtq) ?>"
                            <?= $aplica ? 'title="' . ($activo ? 'Activo — clic para quitar el permiso' : 'Inactivo — clic para dar el permiso') . '"' : 'disabled title="Este permiso no aplica a este rol"' ?>>
                      <span class="switch-knob" aria-hidden="true"></span>
                    </button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>
          <td data-label="Acciones" class="acciones">
            <a href="register.php?id=<?= (int)$u['id'] ?>" class="btn btn-ghost btn-sm">Editar</a>
            <?php if (isset($bloqueados[(int)$u['id']])): ?>
            <form method="POST" class="form-inline">
              <?= csrfField() ?>
              <input type="hidden" name="desbloquear_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm">Desbloquear</button>
            </form>
            <?php endif; ?>
            <?php if (isset($conMfa[(int)$u['id']])): ?>
            <form method="POST" class="form-inline" data-confirm="¿Reiniciar la verificación en dos pasos? La persona deberá configurarla de nuevo (úsalo si perdió el celular).">
              <?= csrfField() ?>
              <input type="hidden" name="reiniciar_mfa_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm">Reiniciar 2 pasos</button>
            </form>
            <?php endif; ?>
            <form method="POST" class="form-inline" data-confirm="¿Confirmas cambiar el estado de esta cuenta?">
              <?= csrfField() ?>
              <input type="hidden" name="toggle_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $u['estado_activo'] ? 'btn-danger' : 'btn-secondary' ?>">
                <?= $u['estado_activo'] ? 'Deshabilitar' : 'Habilitar' ?>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
