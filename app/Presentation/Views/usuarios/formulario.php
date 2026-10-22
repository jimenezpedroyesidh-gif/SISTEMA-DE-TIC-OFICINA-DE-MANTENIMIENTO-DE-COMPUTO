<?php /** Vista del formulario de alta/edición de usuario (public/register.php). Solo presentación: recibe datos ya preparados por RegisterPage. Usa el mismo shell (header + menú) que Usuarios. */ ?>
<?php $paginaTitulo = ($editId ? 'Editar usuario' : 'Crear usuario'); include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Usuarios del sistema'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1><?= $editId ? 'Editar usuario' : 'Crear nuevo usuario' ?></h1>
    </div>

  <section class="panel-box mw-1100">
    <?php if ($error): ?>
        <div class="error mb-3"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($claveTemporalGenerada): ?>
        <div class="mb-3 callout-success">
            <strong>Usuario <?= h($usuarioCreadoNombre) ?> guardado.</strong><br>
            Clave temporal generada — entrégasela a la persona, no vuelve a mostrarse:
            <div class="fs-14em fw-bold ls-2 mt-6px"><?= h($claveTemporalGenerada) ?></div>
            <small>Deberá cambiarla obligatoriamente en su primer ingreso.</small>
        </div>
    <?php endif; ?>

    <form method="POST" class="form-grid">
      <?= csrfField() ?>
      <?php if ($editId): ?>
        <input type="hidden" name="id" value="<?= (int)$editId ?>">
      <?php endif; ?>

      <div class="field">
        <label for="codigo">Código institucional</label>
        <input type="text" id="codigo" name="codigo" required value="<?= h($usuarioEditar['codigo'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="correo">Correo institucional</label>
        <input type="email" id="correo" name="correo" placeholder="ejemplo@<?= h(institucion('dominio_correo')) ?>" required
               pattern="<?= h('^[^@\s]+@(' . implode('|', array_map(fn($d) => preg_quote($d), correoDominiosPermitidos())) . ')$') ?>"
               title="Debe ser un correo <?= h(correoDominiosTexto()) ?>"
               value="<?= h($usuarioEditar['correo'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="nombre_completo">Nombre completo</label>
        <input type="text" id="nombre_completo" name="nombre_completo" required value="<?= h($usuarioEditar['nombre_completo'] ?? '') ?>">
      </div>

      <div class="field">
        <label for="cargo">Cargo</label>
        <select id="cargo" name="cargo" required>
          <option value="">Seleccione el cargo</option>
          <?php
            $cargos = $cargosDisponibles;
            // Un cargo guardado que no está en la lista (usuarios viejos) se muestra igual, para no perderlo al editar.
            $cargoActual = (string)($usuarioEditar['cargo'] ?? '');
            if ($cargoActual !== '' && !in_array($cargoActual, $cargos, true)) $cargos[] = $cargoActual;
          ?>
          <?php foreach ($cargos as $c): ?>
            <option value="<?= h($c) ?>" <?= (($usuarioEditar['cargo'] ?? '') === $c) ? 'selected' : '' ?>><?= h(ucfirst($c)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label for="role">Rol</label>
        <select id="role" name="role" required>
          <?php foreach ($rolesDisponibles as $r): ?>
            <option value="<?= h($r) ?>" <?= (($usuarioEditar['role'] ?? '') === $r) ? 'selected' : '' ?>><?= h(etiquetaRol($r)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php $areaActual = (string)($usuarioEditar['area_beca'] ?? '') ?: AreaBeca::MANTENIMIENTO; ?>
      <div class="field field-full" data-campo-area-beca>
        <label for="area_beca">Área de la beca trabajo</label>
        <select id="area_beca" name="area_beca">
          <?php foreach (AreaBeca::ETIQUETAS as $valorArea => $nombreArea): ?>
            <option value="<?= h($valorArea) ?>" <?= $areaActual === $valorArea ? 'selected' : '' ?><?= in_array($valorArea, AreaBeca::DISPONIBLES, true) ? '' : ' disabled' ?>><?= h($nombreArea) ?><?= in_array($valorArea, AreaBeca::DISPONIBLES, true) ? '' : ' (próximamente)' ?></option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted">Solo aplica al rol beca trabajo. <strong>Oficina de mantenimiento</strong>: atiende los reportes de daño de las salas. <strong>Secretaría</strong>: no ve tickets; trabaja con el tablero de actividades, su turno y sus horas.</small>
      </div>

      <div class="field field-full">
        <label for="id_unidad">Dependencia / departamento / plan de estudios</label>
        <select id="id_unidad" name="id_unidad">
          <option value="">— Ninguna —</option>
          <?php foreach ($unidadesDisponibles as $u): ?>
            <option value="<?= (int)$u['id_unidad'] ?>" <?= ((int)($usuarioEditar['id_unidad'] ?? 0) === (int)$u['id_unidad']) ? 'selected' : '' ?>>
              <?= h($u['nombre']) ?> (<?= h(etiquetaValor($u['tipo'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted">Se elige de la lista única que administra el admin en "Gestionar salas". Para admin/técnico define qué salas ve por defecto en Fichas de equipo. Déjalo en "Ninguna" para ver todas.</small>
      </div>


      <div class="field field-full field-check">
        <label>
          <input type="checkbox" name="estado_activo" value="1" <?= (!$usuarioEditar || !empty($usuarioEditar['estado_activo'])) ? 'checked' : '' ?>>
          Cuenta activa
        </label>
      </div>

      <?php if ($editId): ?>
      <div class="field field-full field-check">
        <label>
          <input type="checkbox" name="resetear_clave" value="1">
          Generar nueva clave temporal (la persona deberá cambiarla en su próximo ingreso)
        </label>
      </div>
      <?php endif; ?>

      <div class="field-full d-flex flex-row gap-2 flex-wrap">
        <button type="submit" class="btn"><?= $editId ? 'Guardar cambios' : 'Crear usuario' ?></button>
        <a href="usuarios.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </section>
  <?php include LAYOUT_DIR . '/app_page_end.php'; ?>
