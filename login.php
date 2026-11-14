<?php
// There is no separate login page anymore. Login happens in a pop-up on the main page.
header("Location: dashboard.php?open=login");
exit();