<?php

header('Location: /viewinvoice.php?' . http_build_query([
        'id' => $_GET['order_id']
    ]));
exit();