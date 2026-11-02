<?php
// CSV export was replaced by the combined Excel export. Redirect anyone who
// still has this URL bookmarked, rather than leaving a dead/broken page.
// Safe to delete this file entirely if you'd rather clean it up by hand.
header('Location: export_excel');
exit;
