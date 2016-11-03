fb-online-schema-change
=======================

## WARNING!!
**This tool affects data in database tables. Read and understand the documentation FULLY before running in production. USE AT YOUR OWN RISK!**

### What is it?
A tool for ALTERing large database tables without blocking reads/writes during the process. It is based on Facebook's own [Online Schema Change for MySQL](https://www.facebook.com/notes/mysql-at-facebook/online-schema-change-for-mysql/430801045932/). It's written in PHP, but compiled as an easy-to-use command-line tool.

INSERTs, UPDATEs and DELETEs to the table can still take place while the change is happening. This tool works against both InnoDB and MyISAM tables.


### How does it work
The tool makes an empty copy of your original table, and runs the given `ALTER` statement against this copy. Because the table is empty, the `ALTER` happens instantly.

It then uses MySQLs `SELECT INTO OUTFILE` to dump data from the original table into files which are then loaded into the new ALTERed table. Copying the data to the new table can take some time--usually longer than the `ALTER` would have taken normally. However, during this time you are free to read and write to the table like normal.

Triggers are set up to catch changes made to the original table while this is happening (INSERTs, UPDATEs, and DELETEs). Once all of the data has been copied, the changes are replayed against the new table in the same order they happened originally.

The package then locks the tables, replays any new changes one last time, and verifies that the original and copied table are in sync. If the tool is satisfied, it finally renames the tables using the `ALTER TABLE` syntax which works on locked tables (unlike `RENAME TABLE`).

 
### How do I use it?
Download the phar archive directly (without the .phar extension) and give it execute permission...

    curl -fsSLo online-schema-change https://github.com/gazugafan/fb-online-schema-change/raw/master/build/online-schema-change.phar
    chmod a+x online-schema-change

Call the script like any other command-line tool. A simple example...

    ./online-schema-change alter mydatabase sometable "ADD INDEX, DROP COLUMN, etc" --user jdoe --password pw0rd

By default, the tool causes MySQL to write files to its own protected data folder, but cannot necessarily delete those files. Consider running with `sudo` to workaround this. To get more verbose output and help debug problems, add `-v`, `-vv`, or `-vvv`.

If the tool fails to complete and for some reason cannot cleanup after itself, run the cleanup command to remove temporary tables, triggers, and files...

    ./online-schema-change cleanup mydatabase --user jdoe --password pw0rd

If you're working on a remote server, log the tool's output to a file in case you lose your SSH connection...

    ./online-schema-change alter mydatabase sometable "ADD INDEX, DROP COLUMN, etc" --user jdoe --password pw0rd --logfile "output.log"
    
... but keep outputting to the stdout still as well...

    ./online-schema-change alter mydatabase sometable "ADD INDEX, DROP COLUMN, etc" --user jdoe --password pw0rd --logfile "osc.log" --stdout


### Other tools
Other tools you might consider are pt-online-schema-change, and GitHub's own gh-ost. I've found that pt-online-schema-change does not necessarily playback changes in the same order they happened originally--wreaking havoc on entries that are changed multiple times and leaving the original and ALTERed table out of sync. gh-ost seems like a more modern and robust solution, but benefits most from having replica database servers and otherwise requires a certain database configuration. For these reasons I haven't yet tried it in production.


### Credits
This tool is a fork of @mrjgreen's work, which itself used a modified version of Facebook's own [Online Schema Change for MySQL](https://www.facebook.com/notes/mysql-at-facebook/online-schema-change-for-mysql/430801045932/) tool. Mrjgreen modified Facebook's original tool to use PDO and a PSR logger, and wrapped it up as an easy-to-use console command tool. I've ported a few additional options from Facebook's tool, fixed a bug with log files, added a cleanup command, and a few other new features such as elegant handling of CTRL+C aborting.
