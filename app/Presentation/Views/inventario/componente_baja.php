<?php /** Vista mínima de un componente DE_BAJA para quien no es admin (public/ficha_componente.php). Recibe $componente y $ubicacionPrevia de FichaComponentePage. */ ?>
<?php $paginaTitulo = 'Componente dado de baja'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<?php $tituloHeader = 'Componente dado de baja'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
<section class="panel-box">
          <h2 class="panel-title"><?= h($componente['codigo_inventario']) ?></h2>
          <p class="text-muted">Este componente fue dado de baja definitivamente y ya no está disponible en el inventario.</p>
          <p><strong>Última ubicación conocida:</strong> <?= h($ubicacionPrevia) ?></p>
        </section>
      </main>
    </div>
    </body>
    </html>
