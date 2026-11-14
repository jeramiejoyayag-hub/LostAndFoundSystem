<?php
// There is no separate register page anymore. Create Account happens in a pop-up on the main page.
header("Location: dashboard.php?open=register");
exit();