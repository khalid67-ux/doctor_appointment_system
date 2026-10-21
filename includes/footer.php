</main>


<!-- =====================================================
     FOOTER
     ===================================================== -->

<footer class="app-footer mt-auto">

    <div class="container py-4">

        <div
            class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3"
        >

            <div class="text-center text-md-start">

                <strong>
                    <i class="bi bi-heart-pulse-fill text-primary me-1"></i>
                    MediBook
                </strong>

                <span>
                    · Dynamic Doctor Appointment & Healthcare Management System
                </span>

            </div>


            <div class="small">

                Academic Project · <?= date('Y') ?>

            </div>

        </div>

    </div>

</footer>


<!-- Bootstrap JavaScript -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<!-- MediBook JavaScript -->

<script
    src="<?= h($asset_prefix ?? '') ?>assets/js/main.js"
></script>


</body>

</html>