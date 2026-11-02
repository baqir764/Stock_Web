<?php
// GRN import was merged into the combined import.php (which keeps GRN and
// Stock Card import as two separate abilities inside one file). Redirect
// anyone who still has this URL bookmarked, rather than leaving a dead page.
// Safe to delete this file entirely if you'd rather clean it up by hand.
header('Location: import?type=grn');
exit;
