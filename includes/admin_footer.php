        </main>
    </div>

    <div class="modal-backdrop" id="confirmModal" hidden>
        <section class="modal confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
            <button class="modal-close" type="button" data-modal-close aria-label="Cerrar">×</button>
            <div class="confirm-icon">!</div>
            <h2 id="confirmTitle">¿Eliminar producto?</h2>
            <p id="confirmText">Esta acción no se puede deshacer.</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="btn btn-danger" type="button" id="confirmDeleteButton">Sí, eliminar</button>
            </div>
        </section>
    </div>

    <script>window.STOCKFLOW_BASE_URL = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= url('assets/js/app.js?v=' . (string) @filemtime(__DIR__ . '/../assets/js/app.js')) ?>" defer></script>
</body>
</html>
