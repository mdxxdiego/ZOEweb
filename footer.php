<?php
$anio = date('Y');
?>

<style>
    .main-footer {
        background-color: #5D75A4;
        color: #ffffff;
        padding: 25px 0;
        margin-top: 40px;
        border-top: 4px solid #24476D;
        width: 100%;
        text-align: center;
    }

    .footer-content {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 15px;
    }

    .footer-text-ist {
        font-weight: bold;
        margin: 0 0 8px 0;
    }

    .footer-credits {
        font-size: 0.85rem;
        opacity: 0.9;
        margin: 0;
    }
</style>

<footer class="main-footer">
    <div class="footer-content">
        <p class="footer-text-ist">
            ZOE FACTURACIÓN ELECTRÓNICA Ver. 0.8.13
        </p>
        <p class="footer-credits">
            &copy; <?php echo $anio; ?> DieDay Soft. | Zoe and DieDay Soft. are registered trademarks of Diego Darquea</strong>
        </p>
    </div>
</footer>