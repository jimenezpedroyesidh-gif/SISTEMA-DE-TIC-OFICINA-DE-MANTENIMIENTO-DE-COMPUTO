<?php /** Vista de «Escanear QR» (public/escanear_qr.php). Solo presentación: $shell lo prepara EscanearQrPage. */ ?>
<?php $paginaTitulo = 'Escanear QR de sala'; include LAYOUT_DIR . '/app_page_head.php'; ?>
<script src="<?= h(asset('assets/vendor/html5-qrcode-2.3.8/html5-qrcode.min.js')) ?>"></script>
<?php $tituloHeader = 'Escanear QR de sala'; include LAYOUT_DIR . '/app_page_body_start.php'; ?>
    <div class="app-dashboard-welcome">
      <h1>Escanear QR de sala</h1>
      <p>Apunta la cámara al código QR pegado en la sala para reportar o consultar su estado.</p>
    </div>

  <section class="panel-box">
    <div class="scanner-wrap">
      <div id="qr-reader"></div>
      <div id="qr-status" class="scanner-msg">Solicitando acceso a la cámara…</div>

      <?php /* Alternativa manual si la cámara no está disponible o el permiso es denegado */ ?>
      <div id="fallback" hidden class="mt-4">
        <p class="mb-2">También puedes escribir el token del QR a mano:</p>
        <form id="form-token">
          <div class="field">
            <label for="token_manual">Token o enlace del QR</label>
            <input type="text" id="token_manual" placeholder="Pega aquí el token (sala) o el enlace completo (equipo/componente)">
          </div>
          <button type="submit" class="btn">Continuar</button>
        </form>
      </div>
    </div>
  </section>
  <?php include LAYOUT_DIR . '/app_page_main_end.php'; ?>

<script src="<?= h(asset('assets/escanear_qr.js')) ?>"></script>
</body>
</html>
