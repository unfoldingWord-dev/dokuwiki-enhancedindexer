enhancedindexer Plugin for DokuWiki

Replaces the indexer used by dokuwiki to one that is intended to be run via a cron job

The default indexer action which is triggered via the webbug 1x1 gif on each page is
overridden to not do the index action but to allow the other actions that happen in 
/lib/exe/indexer.php

Also extra options to the cli indexer

If you install this plugin manually, make sure it is installed in
lib/plugins/enhancedindexer/ - if the folder is called different it
will not work!

Please refer to http://www.dokuwiki.org/plugins for additional info
on how to install plugins in DokuWiki.

After it's installed just run with:
`php path-to-dokuwiki/lib/plugins/enhancedindexer/bin/indexer.php`

then add to a 5 min or less frequent cron job.

----
Copyright (C) David Stone <david@nnucomputerwhiz.com>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

See the COPYING file in your DokuWiki folder for details
