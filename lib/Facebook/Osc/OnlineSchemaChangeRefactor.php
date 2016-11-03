<?php
// Copyright 2004-present Facebook. All Rights Reserved.

/*
Copyright 2010 Facebook. All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

  1. Redistributions of source code must retain the above copyright notice, this
     list of conditions and the following disclaimer.

  2. Redistributions in binary form must reproduce the above copyright notice,
     this list of conditions and the following disclaimer in the documentation
     and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY Facebook ``AS IS'' AND ANY EXPRESS OR
IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO
EVENT SHALL <COPYRIGHT HOLDER> OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

The views and conclusions contained in the software and documentation are those
of the authors and should not be interpreted as representing official policies,
either expressed or implied, of Facebook.

The following sections in the code have been taken from
http://code.google.com/p/openarkkit/ authored by Shlomi Noach and adapted.
1. Splitting the scan of the original table into multiple scans based on PK
   ranges. Refer methods initRangeVariables(), refreshRangeStart(),
   assignRangeEndVariables(), getRangeStartCondition().
The code taken from http://code.google.com/p/openarkkit/ comes with the
following license:
Copyright (c) 2008-2009, Shlomi Noach
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:
* Redistributions of source code must retain the above copyright notice, this
  list of conditions and the following disclaimer.
* Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.
* Neither the name of the organization nor the names of its contributors may be
  used to endorse or promote products derived from this software without
  specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

*/

/**
 * OnlineSchemaChange class encapsulates all the steps needed to do online
 * schema changes. Only public functions by category are
 *  __construct(), execute(), forceCleanup()
 * static methods getOscLock(), releaseOscLock(), isOscLockHeld()
 * static methods getCleanupTables(), serverCleanup() and dropTable().
 *
 * execute() returns true/false indicating success or failure. In rare
 * cases where an exception occurs while cleaning up, it may raise an exception.
 * In that case, caller needs to run OSC with OSC_FLAGS_FORCE_CLEANUP to clean
 * up triggers/tables/files created by OSC (calling forceCleanup() method would
 * run OSC with this flag).
 *
 * IMPORTANT ASSUMPTION:
 * Caller needs to check that there are no foreign keys to/from table being
 * altered and that there are no triggers already defined on the table.
 *
 * @author: nponnekanti with some parts authored by Shlomi Noach as noted
 * in the license section above.
 */

// Default value for flags is 0.
//
// OSC_FLAGS_CHECKSUM causes checksum comparison before switching tables.
// Checksum is compared while holding table lock. ALso, it only makes sense
// in some cases. For example, when adding new column, checksum will NOT
// match by design. However when adding index on existing column, or when
// rebuilding table without schema changes, checksum is expected to match.
//
// OSC_FLAGS_FORCE_CLEANUP causes ONLY cleanup to be done, i.e no schema
// change. It cleans up triggers/tables/outfiles left over from prior OSC
// run. It treats errors as warnings and proceeds forward rather than stopping
// on 1st error.

// drops renamed original table
define('OSC_FLAGS_DROPTABLE', 0x00000001);
// don't do cleanup (helps testing)
define('OSC_FLAGS_NOCLEANUP', 0x00000008);
// see note above on this flag
define('OSC_FLAGS_CHECKSUM', 0x00000010);
// bypasses some checks on new PK
define('OSC_FLAGS_ACCEPTPK', 0x00000020);
// Allow a column to be dropped
define('OSC_FLAGS_DROPCOLUMN', 0x00000040);
// bypasses version check
define('OSC_FLAGS_ACCEPT_VERSION', 0x00000400);
// use upto 54 char prefix of table name in trigger/table names
define('OSC_FLAGS_NAMEPREFIX', 0x00000800);
// run OSC ignoring long xact
define('OSC_FLAGS_LONG_XACT_OK', 0x00001000);
// forces usage of newly added PK
define('OSC_FLAGS_USE_NEW_PK', 0x00004000);
// when adding PK or UNIQUE, delete duplicates
define('OSC_FLAGS_ELIMINATE_DUPS', 0x00008000);


// useful for enclosing column names, index names and table names in quotes
function oscQuotify($name)
{
    return '`' . $name . '`';
}

class IndexColumnInfo
{
    public $name;
    public $prefix; // used when index is on prefix of a column

    // Note that ascending/descending is not currently supported.
    public function __construct($input_name, $input_prefix)
    {
        $this->name = $input_name;
        $this->prefix = $input_prefix;
    }
}

class IndexInfo
{

    // No index type because innodb only allows Btree.
    // Also note that spatial and fulltext indexes are not available in Innodb.
    public function __construct($input_tablename, $input_indexname,
                                $input_non_unique, $input_is_auto_increment)
    {
        $this->tablename = $input_tablename;
        $this->indexname = $input_indexname;
        $this->nonUnique = $input_non_unique;
        $this->isAutoIncrement = $input_is_auto_increment;
        $this->columns = array();
    }


    public function addColumn($input_name, $input_prefix = '')
    {
        $column = new IndexColumnInfo($input_name, $input_prefix);
        $this->columns[] = $column;
    }

    public function getCreateSql()
    {

        $columnlist = '';

        $comma = ''; // no comma first time
        foreach ($this->columns as $column) {
            $prefix = ($column->prefix == '' ? '' : sprintf('(%d)', $column->prefix));
            $columnlist .= $comma . $column->name . $prefix;
            $comma = ',';
        }

        $unique = ($this->nonUnique ? '' : 'unique');
        $create = sprintf(' ADD %s index %s (%s) ',
            $unique, $this->indexname, $columnlist);
        return $create;
    }

    public function isPrimary()
    {
        return ($this->indexname === oscQuotify('PRIMARY'));
    }

    public function getDropSql()
    {
        $drop = sprintf('drop index %s on %s', $this->indexname, $this->tablename);
        return $drop;
    }


}

class OnlineSchemaChangeRefactor
{

    const IDCOLNAME = '_osc_ID_';
    const DMLCOLNAME = '_osc_dml_type_';

    // Note that an update that affects PK is mapped as delete followed by insert
    const DMLTYPE_INSERT = 1;
    const DMLTYPE_DELETE = 2;
    const DMLTYPE_UPDATE = 3;

    const TEMP_TABLE_IDS_TO_EXCLUDE = '__osc_temp_ids_to_exclude';
    const TEMP_TABLE_IDS_TO_INCLUDE = '__osc_temp_ids_to_include';

    // we only retry on timeout or deadlock error
    const LOCK_MAX_ATTEMPTS = 3;

    // Names (such as table name etc) can be maxlen of 64.
    const LIMITNAME = 64;
    // A value that is at least LIMITNAME + length of prefix that OSC adds when
    // generating names for triggers and tables.
    const NOLIMIT = 100;

    // the string that OSC gets lock on
    const LOCK_STRING = "OnlineSchemaChange";

    // A static method that can be used by other scripts to check if OSC
    // lock is held.
    // Returns connection id of the connection that holds OSC lock if any.
    public static function isOscLockHeld(\PDO $conn)
    {
        $lock_command = sprintf("select is_used_lock('%s') as osc_connection_id", self::LOCK_STRING);

        $lock_result = $conn->query($lock_command);

        $row = $lock_result->fetch();

        if (!$row) {
            Throw new Exception("Returned no rows:" . $lock_command);
        }

        return $row['osc_connection_id'];
    }

    // Returns array of (dbname, tablename) arrays for which OSC cleanup may be
    // needed. Basically looks for triggers/tables that may have been left behind
    // by OSC. It does not look for outfiles though as the main use case is
    // to cleanup tables/triggers that may have been inadvertantly captured in a
    // db backup, and hence to restored database.
    public static function getCleanupTables(\PDO $conn)
    {
        $q1 = "(select T.table_schema as db, substr(T.table_name, 11) as obj " .
            " from information_schema.tables T " .
            " where T.table_name like '__osc_%')";
        $q2 = "(select T.trigger_schema as db, substr(T.trigger_name, 11) as obj " .
            " from information_schema.triggers T " .
            " where T.trigger_name like '__osc_%')";
        $q = $q1 . " union distinct " . $q2 . " order by db, obj";

        $result = $conn->query($q);


        $db_and_tables = array();
        while ($row = $result->fetch()) {
            $db_and_tables[] = array('db' => $row['db'],
                'table' => $row['obj']);
        }

        return $db_and_tables;
    }

    // Connects to the server identified by $sock, $user, $password and cleans up
    // any left over tables/triggers left over by OSC in any database.
    // Main use case is as follows:
    // If a db is backed up inadvertantly while OSC is running, it may have some
    // OSC tables/triggers in it and there is a need to remove them.
    //
    // $flags is the same as for __construct documented above,
    // but the main flags of interest for cleanup are OSC_FLAGS_DELETELOG,
    // OSC_FLAGS_DROPTABLE.
    //
    public static function serverCleanup(\PDO $pdo, Psr\Log\LoggerInterface $logger, $output_folder = null, $flags = 0)
    {
        self::releaseOscLock($pdo);

        $db_and_tables = self::getCleanupTables($pdo);

        foreach ($db_and_tables as $db_and_table) {
            $db = $db_and_table['db'];
            $table = $db_and_table['table'];
            $ddl = ''; // empty alter table command as we don't intend to alter
            $osc = new self($pdo, $logger, $db, $table, $ddl, $output_folder, $flags);

            $osc->forceCleanup();
        }

    }

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;


    /**
     * $input_sock              -  socket to use
     * $input_user              -  username to use to connect
     * $input_password          -  password to use to connect
     * $input_dbname            -  database name
     * $input_tablename         -  table being altered
     * $input_altercmd         -  alter table DDL. See below.
     * $input_outfile_folder    -  folder for storing outfiles. See below.
     * $input_flags             -  various flags described below
     * $input_batchsize_load    -  batchsize to use when selecting from table to
     * outfiles. Each outfile generated (except last
     * one) will have this many rows.
     * $input_batchsize_replay  -  transaction size to use during replay phase.
     * Commit after this many single row
     * insert/update/delete commands are replayed.
     * $input_long_xact_time    -  If OSC finds a long running xact running for
     * this many seconds, it bails out unless
     * OSC_FLAGS_LONG_XACT_OK is set.
     * $input_logfile_folder    -  folder for storing logfiles. See below.
     * $input_linkdir           -  symlimk support. End with /. See below.
     *
     *
     * $input_altercmd:
     * OSC works by making a copy of the table, doing schema change on the table,
     * replaying changes and swapping the tables. While this input_altercmd
     * would create the original table, OSC needs to modify to to affect the
     * copytable.
     *
     * It first replaces 'create table ' (case insensitive and with possible
     * multiple spaces before and after table) with 'CREATE TABLE ' (upper case and
     * with exactly one space before and after TABLE).
     *
     * Then it replaces 'CREATE TABLE <originaltable>' with
     * 'CREATE TABLE <copytable>'. This is case sensitive replace since table names
     * are case sensitive.
     *
     * While doing the above replaces, if there is no match or > 1 matches, it
     * raises exception. (So if u have comments etc which contain 'create table' or
     * 'create table <tablename>', it may find > 1 match and raise exception.
     *
     * $input_outfile_folder (end with /):
     * If a folder name is supplied it is used. The folder must exist. Otherwise
     * invalid outfile folder exception is raised. Otherwise, if @@secure_file_priv
     * is non-null, it is used. Otherwise @@datadir/<dbname> folder is used. (It is
     * assumed that @@datadir is not NULL.)
     *
     * $input_logfile_folder (end with /):
     * Used for storing osc log files.
     *
     */
    public function __construct(\PDO $pdo,
                                \Psr\Log\LoggerInterface $logger,
                                $input_dbname,
                                $input_tablename,
                                $input_altercmd,
                                $input_outfile_folder = null,
                                $input_flags = 0,
                                $input_batchsize_load = 500000,
                                $input_batchsize_replay = 500,
                                $input_long_xact_time = 30,
                                $input_backup_user = "backup")
    {

        $this->logger = $logger;
        $this->dbname = trim($input_dbname, '`'); // remove quotes if present
        $this->qdbnameq = oscQuotify($this->dbname); // quoted dbname
        $this->tablename = trim($input_tablename, '`'); // remove quotes if present
        $this->qtablenameq = oscQuotify($this->tablename); // quoted tablename
        $this->flags = $input_flags;
        $this->batchsizeLoad = $input_batchsize_load;
        $this->batchsizeReplay = $input_batchsize_replay;
        $this->outfileFolder = ($input_outfile_folder?(rtrim($input_outfile_folder, '/') . '/'):null);
        $this->backup_user = $input_backup_user;
        $this->longXactTime = $input_long_xact_time;


        $this->altercmd = $input_altercmd;


        // set to IGNORE or empty to add to queries which manipulate the table
        $this->ignoredups = $input_flags & OSC_FLAGS_ELIMINATE_DUPS ? 'IGNORE' : '';

        // In all the tables/triggers that OSC creates, keep the tablename
        // starting at exactly 11th character so that it is easy to get the
        // original tablename from the object (i.e prefix is 10 chars).
        // Mysql allows only 64 characters in names. Adding prefix can make
        // it longer and cause failure in mysql. Let it fail by default. If
        // caller has set OSC_FLAGS_NAMEPREFIX, then use prefix of tablename.
        // However that makes it impossible to construct original tablename
        // from the name of the object. So methods like getCleanupTables
        // may not correctly return tablenames.

        $limit = (($this->flags & OSC_FLAGS_NAMEPREFIX) ?
            self::LIMITNAME :
            self::NOLIMIT);

        // table to capture deltas
        $this->deltastable = substr('__osc_chg_' . $this->tablename, 0, $limit);


        // trigger names for insert, delete and update
        $this->insertTrigger = substr('__osc_ins_' . $this->tablename, 0, $limit);
        $this->deleteTrigger = substr('__osc_del_' . $this->tablename, 0, $limit);
        $this->updateTrigger = substr('__osc_upd_' . $this->tablename, 0, $limit);

        // new table name
        $this->newtablename = substr('__osc_new_' . $this->tablename, 0, $limit);
        $this->renametable = substr('__osc_old_' . $this->tablename, 0, $limit);

        $this->isSlaveStopped = false;

        $this->conn = $pdo;

        $this->openAndInitConnection();
    }


    // this opens connection, switches off binlog, gets OSC lock, does a use db
    protected function openAndInitConnection()
    {
        $this->turnOffBinlog();
        $this->setSqlMode();

        $this->executeSql('Selecting database', 'use ' . $this->qdbnameq);

        // get this lock as soon as possible as this lock is used to
        // determine if OSC is running on the server.
        $this->getOscLock($this->conn);

        return $this->conn;
    }

    // Gets OSC lock. Used within OSC, and possibly other scripts
    // to prevent OSC from running. Throws exception if lock cannot
    // be acquired.
    public static function getOscLock(\PDO $conn)
    {
        $lock_command = sprintf("select get_lock('%s', 0) as lockstatus",
            self::LOCK_STRING);

        $statement = $conn->query($lock_command);

        if (!($row = $statement->fetch())) {
            Throw new Exception("GET_LOCK returned no rows");
        }

        if ($row['lockstatus'] != 1) {
            Throw new Exception("GET_LOCK returned " . $row['lockstatus']);
        }

    }

    // Releases OSC lock. Does not return anything.
    // Throws exception if release_lock statement fails, such as if connection
    // is not valid. However, if lock was not held, it just silently returns.
    public static function releaseOscLock(\PDO $conn)
    {
        $lock_command = sprintf("do release_lock('%s')", self::LOCK_STRING);

        $conn->query($lock_command);

        if ($pid = self::isOscLockHeld($conn)) {
            $kill_command = sprintf("kill %s", $pid);
            $conn->query($kill_command);
        }
    }

    // Connect to the server identified by $sock, $user, $password and drop
    // table specified by by $table. If the table is partitioned we will drop
    // a patition at a time in order to avoid performance issues associated with
    // dropping all partitions at the same time.
    public static function dropTable($tablename, \PDO $conn)
    {
        $show_query = "SHOW CREATE TABLE `$tablename`";

        try{
            $statement = $conn->query($show_query);
        }
        catch(\PDOException $e)
        {
            if(false !== strpos($e->getMessage(), 'doesn\'t exist'))
            {
                return false;
            }

            throw $e;
        }

        $tbl_dfn = $statement->fetchColumn(1);

        $partitions = array();
        // Cycle through each partition and delete them one at a time
        if (preg_match_all("/PARTITION ([^ ]+) VALUES/", $tbl_dfn, $partitions)) {
            $partitions = $partitions[1];
            // length(table) - 1 otherwise we leave a paritioned table with no
            // partitions, which MySQL errors on.
            array_pop($partitions);
            foreach ($partitions as $partition) {
                // Intentionally ignoring any issues.
                $drop_query = "ALTER TABLE `$tablename` DROP PARTITION `$partition`";
                $conn->query($drop_query);
            }
        }
        // Intentionally ignoring any issues. We sometimes call
        // drop table unnecessarily.
        $drop_query = "DROP TABLE IF EXISTS `$tablename`";

        $conn->query($drop_query);
    }


    protected function getSlaveStatus()
    {
        $query = 'show slave status';

        $stmt = $this->executeSql('Checking slave status',$query);

        // if rows are returned, it means we are running on a slave
        if ($row = $stmt->fetch()) {
            return (($row['Slave_IO_Running'] == "Yes") &&
                ($row['Slave_SQL_Running'] == "Yes"));
        } else {
            // not configured as slave.
            return false;
        }
    }

    // if slave is running, then stop it
    protected function stopSlave()
    {
        if ($this->getSlaveStatus()) {
            $this->executeSql('stopping slave', 'stop slave');
            $this->isSlaveStopped = true;
        }
    }

    // we start slave only if we stopped it
    protected function startSlave()
    {
        if ($this->isSlaveStopped) {
            $this->isSlaveStopped = false;
            $this->executeSql('starting slave', 'start slave');
        }
    }

    // wrapper around unlink
    protected function executeUnlink($file, $ignoreError = false)
    {
        if (!file_exists($file)) {
            $this->logger->debug("File " . $file . " does not exist");
            return false;
        }

        if (!unlink($file)) {
            if ($ignoreError) {
                // log and move on
                $this->logger->warning("Could not delete file:" . $file);
                return false;
            } else {
                throw new RuntimeException('Could not delete file:' . $file, false);
            }
        }

        $this->logger->info("Deleted file:" . $file);

        return true;
    }

    // wrapper around mysql_query
    // used for sql commands for which we don't have a resultset
    // logflags is used to specify:
    // whether to log always (default) or only in verbose mode (LOGFLAG_VERBOSE)
    // whether failure is error (default) or warning (LOGFLAG_WARNING)
    protected function executeSql($sql_description, $sql, $ignoreErrors = false)
    {
        $this->logger->info($sql_description);

        $this->logger->debug($sql);

        try
        {
            $stmt = $this->conn->query($sql);
        }catch (\PDOException $e)
        {
            $this->logger->error("ERROR: SQL : " . $sql . ". Error : " . $e->getMessage());

            if ($ignoreErrors) {
                // log error and move on
                $this->logger->notice("Force cleanup enabled, catching exception");
                return false;
            }

            throw $e;
        }

        return $stmt;
    }

    protected function turnOffBinlog()
    {
        $this->executeSql('Turning binlog off', 'SET sql_log_bin = 0');
    }

    protected function setSqlMode()
    {
        $this->executeSql('Setting sql_mode to STRICT_ALL_TABLES', 'SET sql_mode = STRICT_ALL_TABLES');
    }

    // some header info that is useful to log
    protected function getOSCHeader()
    {
        $logtext = "--OSC info: time=%s, db=%s, table=%s, flags=%x\n" .
            "--CREATE=%s\n";
        $logtext = sprintf($logtext, date('c'), $this->dbname, $this->tablename,
            $this->flags, $this->altercmd);
        return $logtext;
    }


    // Retrieves column names of table being altered and stores in array.
    // Stores PK columns, non-PK columns and all columns in separate arrays.
    protected function initColumnNameArrays()
    {
        $this->columnarray = array();
        $this->pkcolumnarray = array();
        $this->nonpkarray = array();
        $this->nonpkcolumns = '';

        // get list of columns in new table
        $query = "select column_name " .
            "from information_schema.columns " .
            "where table_name ='%s' and table_schema='%s'";
        $query = sprintf($query, $this->newtablename, $this->dbname);

        $result = $this->executeSql('Selecting columns from new copy table',$query);

        $newcolumns = array();
        while ($row = $result->fetch()) {
            $newcolumns[] = $row['column_name'];
        }

        $query = "select column_name, column_key, extra " .
            "from information_schema.columns " .
            "where table_name ='%s' and table_schema='%s'";
        $query = sprintf($query, $this->tablename, $this->dbname);

        $result = $this->executeSql('Selecting columns from existing table',$query);

        $comma = ''; // no comma first time
        while ($row = $result->fetch()) {
            // column must have been dropped from new table, skip it
            if (!in_array($row['column_name'], $newcolumns)) {
                continue;
            }
            $column_name = oscQuotify($row['column_name']);
            $this->columnarray[] = $column_name;
            // there should be atmost one autoincrement column
            if (stripos($row['extra'], 'auto_increment') !== false) {
                if (isset($this->autoIncrement)) {
                    $err = sprintf("Two auto_increment cols: %s, %s",
                        $this->autoIncrement, $column_name);
                    throw new RuntimeException($err, false);
                }
                $this->autoIncrement = $column_name;
            }
            if ($row['column_key'] != 'PRI') {
                $this->nonpkarray[] = $column_name;
                $this->nonpkcolumns .= $comma . $column_name;
                $comma = ',';
            }
        }

        // for PK columns we need them to be in correct order as well.
        $query = "select * from information_schema.statistics " .
            "where table_name = '%s' and TABLE_SCHEMA = '%s' " .
            "  and INDEX_NAME = 'PRIMARY' " .
            "order by INDEX_NAME, SEQ_IN_INDEX";

        $query = sprintf($query, $this->tablename, $this->dbname);

        $result = $this->executeSql('Selecting PK columns from existing table',$query);

        $this->pkcolumnarray = array();
        while ($row = $result->fetch()) {
            $this->pkcolumnarray[] = oscQuotify($row['COLUMN_NAME']);
        }

        if (!($this->flags & OSC_FLAGS_USE_NEW_PK) && count($this->pkcolumnarray) === 0) {
            throw new RuntimeException("No primary key defined on the table!", false);
        }

    }

    // This is dependent on initColumnNameArray().
    // Uses the array of column names created by the former function
    // to come up with a string of comma separated columns.
    // It also builds strings of comma separated columns where each column is
    // prefixed with "NEW." and "OLD.".
    protected function initColumnNameStrings()
    {
        $this->columns = '';
        $this->oldcolumns = '';
        $this->newcolumns = '';
        $this->pkcolumns = '';
        $this->oldpkcolumns = '';
        $this->newpkcolumns = '';
        $comma = ''; // no comma at the front

        foreach ($this->columnarray as $column) {
            $this->columns .= $comma . $column;
            $this->oldcolumns .= $comma . 'OLD.' . $column;
            $this->newcolumns .= $comma . 'NEW.' . $column;
            $comma = ', '; // add comma from 2nd column
        }

        $comma = ''; // no comma at the front

        foreach ($this->pkcolumnarray as $column) {
            $this->pkcolumns .= $comma . $column;
            $this->oldpkcolumns .= $comma . 'OLD.' . $column;
            $this->newpkcolumns .= $comma . 'NEW.' . $column;
            $comma = ', '; // add comma from 2nd column
        }

    }

    protected function initRangeVariables()
    {
        $count = count($this->pkcolumnarray);
        $comma = ''; // no comma first time

        $this->rangeStartVars = '';
        $this->rangeStartVarsArray = array();
        $this->rangeEndVars = '';
        $this->rangeEndVarsArray = array();

        for ($i = 0; $i < $count; $i++) {
            $startvar = sprintf("@range_start_%d", $i);
            $endvar = sprintf("@range_end_%d", $i);
            $this->rangeStartVars .= $comma . $startvar;
            $this->rangeEndVars .= $comma . $endvar;
            $this->rangeStartVarsArray[] = $startvar;
            $this->rangeEndVarsArray[] = $endvar;

            $comma = ',';
        }
    }

    protected function refreshRangeStart()
    {
        $query = sprintf(" SELECT %s INTO %s ",
            $this->rangeEndVars, $this->rangeStartVars);
        $this->executeSql('Refreshing range start', $query);
    }


    // Initializes names of files (names only and not contents) to be used as
    // OUTFILE targets in SELECT INTO
    protected function initOutfileNames()
    {

        if (!empty($this->outfileFolder) && !file_exists($this->outfileFolder)) {
            throw new RuntimeException("Invalid outfile folder " . $this->outfileFolder,
                false);
        }

        // if no folder specified for outfiles use @@secure_file_priv
        if (empty($this->outfileFolder)) {

            $query = 'select @@secure_file_priv as folder';

            $result = $this->executeSql('Selecting a privileged folder',$query);

            // we expect only one row
            while ($row = $result->fetch()) {
                $this->outfileFolder = $row['folder'];
            }

        }

        // if @@secure_file_priv is also empty, use @@datadir
        if (empty($this->outfileFolder)) {
            $this->outfileFolder = $this->getDataDir();

            // Add folder for this database
            $this->outfileFolder .= $this->dbname . '/';

        } else {
            // Make sure it ends with / but don't add two /
            $this->outfileFolder = rtrim($this->outfileFolder, '/') . '/';
        }
		
        $this->outfileTable = $this->outfileFolder . '__osc_tbl_' . $this->tablename;
        $this->outfileExcludeIDs = $this->outfileFolder .
            '__osc_ex_' . $this->tablename;
        $this->outfileIncludeIDs = $this->outfileFolder .
            '__osc_in_' . $this->tablename;
    }

    protected function validateVersion()
    {
        $query = 'select version() as version';
        $result = $this->executeSql('Reading MySQL version',$query);

        // we expect only one row
        while ($row = $result->fetch()) {
            $version = $row['version'];
        }

        $version_major = strtok($version, ".");
        $version_minor = strtok(".");
        $version_mini = strtok("_");

        $this->version = sprintf("%s.%s.%s", $version_major, $version_minor,
            $version_mini);

        if ((!($this->flags & OSC_FLAGS_ACCEPT_VERSION)) &&
            (($this->version < "5.0.84") || ($this->version > "5.1.63"))
        ) {
            $error = "OSC has only been tested on versions 5.0.84, 5.1.47, 5.1.50 " .
                "and 5.1.52. Running on " . $this->version . " is not allowed " .
                "unless OSC_FLAGS_ACCEPT_VERSION flag is set.";
            throw new RuntimeException($error);
        }

        $this->logger->notice("MySQL version: $this->version");

        return $this->version;
    }

    // checks for long running xact
    protected function checkLongXact()
    {
        if ($this->flags & OSC_FLAGS_LONG_XACT_OK) {
            return;
        }

        $query = "show full processlist";
        $result = $this->executeSql('Listing running MySQL processes',$query);

        $msg = '';
        $count = 0;
        while ($row = $result->fetch()) {
            if ((empty($row['Time']) || ($row['Time'] < $this->longXactTime)) ||
                ($row['db'] !== $this->dbname) || ($row['Command'] === 'Sleep')
            ) {
                continue;
            }
            $count++;
            $buf = "Id=%d,user=%s,host=%s,db=%s,Command=%s,Time=%d,Info=%s\n";
            $msg .= sprintf($buf, $row['Id'], $row['User'], $row['Host'], $row['db'],
                $row['Command'], $row['Time'], $row['Info']);
        }

        if ($count > 0) {
            $msg = sprintf("%d long running xact(s) found.\n" . $msg, $count);
            throw new RuntimeException($msg);
        }

    }

    protected function init()
    {

        // store pkcolumns, all columns and nonpk columns
        $this->initColumnNameArrays();
        $this->initColumnNameStrings();

        $this->initRangeVariables();
        $this->initIndexes();

    }

    // creates a table (called deltas table) to capture changes to the table
    // being processed during the course of schema change.
    protected function createDeltasTable()
    {
        // deltas has an IDCOLNAME, DMLCOLNAME, and all columns as original table
        $createtable = 'create table if not exists %s' .
            '(%s INT AUTO_INCREMENT, %s INT, primary key(%s)) ' .
            'ENGINE=InnoDB ' .
            'as (select %s from %s LIMIT 0)';
        $createtable = sprintf($createtable, $this->deltastable,
            self::IDCOLNAME, self::DMLCOLNAME, self::IDCOLNAME,
            $this->columns, $this->qtablenameq);

        $this->executeSql('Creating deltas table', $createtable);
        $this->cleanupDeltastable = true;
    }

    // creates insert trigger to capture all inserts in deltas table
    protected function createInsertTrigger()
    {
        $trigger = 'create trigger %s AFTER INSERT ON %s FOR EACH ROW ' .
            'insert into %s(%s, %s) ' .
            'values (%d, %s)';
        $trigger = sprintf($trigger, $this->insertTrigger, $this->qtablenameq,
            $this->deltastable, self::DMLCOLNAME, $this->columns,
            self::DMLTYPE_INSERT, $this->newcolumns);
        $this->executeSql('Creating insert trigger', $trigger);
        $this->cleanupInsertTrigger = true;
    }

    // Creates delete trigger to capture all deletes in deltas table
    // We must dump all columns or else we will encounter issues with
    // columns which are NOT NULL and lack a default
    protected function createDeleteTrigger()
    {
        $trigger = 'create trigger %s AFTER DELETE ON %s FOR EACH ROW ' .
            'insert into %s(%s, %s) ' .
            'values (%d, %s)';
        $trigger = sprintf($trigger, $this->deleteTrigger, $this->qtablenameq,
            $this->deltastable, self::DMLCOLNAME, $this->columns,
            self::DMLTYPE_DELETE, $this->oldcolumns);
        $this->executeSql('Creating delete trigger', $trigger);
        $this->cleanupDeleteTrigger = true;
    }

    // creates update trigger to capture all updates in deltas table
    protected function createUpdateTrigger()
    {
        // if primary key is updated, map the update to delete followed by insert
        $trigger = 'create trigger %s AFTER UPDATE ON %s FOR EACH ROW  ' .
            'IF (%s) THEN ' .
            '  insert into %s(%s, %s) ' .
            '  values(%d, %s); ' .
            'ELSE ' .
            '  insert into %s(%s, %s) ' .
            '  values(%d, %s), ' .
            '        (%d, %s); ' .
            'END IF';
        $trigger = sprintf($trigger, $this->updateTrigger, $this->qtablenameq,
            $this->getMatchCondition('NEW', 'OLD'),
            $this->deltastable, self::DMLCOLNAME, $this->columns,
            self::DMLTYPE_UPDATE, $this->newcolumns,
            $this->deltastable, self::DMLCOLNAME, $this->columns,
            self::DMLTYPE_DELETE, $this->oldcolumns,
            self::DMLTYPE_INSERT, $this->newcolumns);

        $this->executeSql('Creating update trigger', $trigger);
        $this->cleanupUpdateTrigger = true;
    }

    /**
     * The function exists because if lock table is run against a
     * table being backed up, then the table will be locked until
     * the end of the dump. If that happens then Online Schema Change
     * is not so online
     */
    protected function killSelects($table)
    {
        $sql = "SHOW FULL PROCESSLIST ";

        $result = $this->executeSql('Listing running MySQL processes',$sql);

        while ($row = $result->fetch()) {

            if ($row['db'] == $this->dbname &&
                $row['User'] == $this->backup_user &&
                stripos($row['Info'], 'SELECT ') !== FALSE &&
                stripos($row['Info'], 'INFORMATION_SCHEMA') === FALSE
            ) {
                $kill = sprintf("KILL %s", $row[0]);
                // Note, we should not throw an exception if the kill fails.
                // The connection might have gone away on its own.
                try
                {
                    $this->executeSql("Killing dump query", $kill);
                }catch (\PDOException $e)
                {
                    $this->logger->warning($e->getMessage());
                }

            }
        }
    }


    /**
     * Important Assumption: Retrying on deadlock/timeout error assumes
     * that lock tables is the first step in a transaction. Otherwise
     * other locks acquired prior to lock tables could be released and it
     * won't make sense to just retry lock tables.
     */
    protected function lockTables($lock_reason, $lock_both_tables)
    {
        $this->killSelects($this->qtablenameq);

        if ($lock_both_tables) {
            $this->killSelects($this->newtablename);
            $lock = sprintf('lock table %s WRITE, %s WRITE',
                $this->qtablenameq, $this->newtablename);
        } else {
            $lock = sprintf('lock table %s WRITE', $this->qtablenameq);
        }

        $lockWaitRetries = self::LOCK_MAX_ATTEMPTS;

        do {
            try {
                return $this->executeSql($lock_reason, $lock);
            } catch (\PDOException $e) {}
        } while ($this->exceptionIsLockWaitTimeout($e) && $lockWaitRetries--);

    }

    /**
     * @param PDOException $e
     * @return bool
     */
    private function exceptionIsLockWaitTimeout(\PDOException $e)
    {
        return strpos($e->getMessage(), 'try restarting transaction') !== false;
    }

    protected function createTriggers()
    {
        $this->stopSlave();

        // without turning off autocommit lock tables is not working
        $this->executeSql('AUTOCOMMIT OFF', 'set session autocommit=0');

        // In 5.0 version creating a trigger after locking a table causes hang.
        // So we will lock a little later.
        // Refer intern/wiki/index.php/Database/Online_Schema_Change_Testing and
        // search for workaround for more info.
        if ($this->version !== "5.0.84") {
            // false means lock only original table
            $this->lockTables('LOCKING table to drain pre-trigger Xacts', false);
        }

        $this->createInsertTrigger();

        $this->createDeleteTrigger();

        $this->createUpdateTrigger();

        // for other version we have already locked above.
        if ($this->version === "5.0.84") {
            // false means lock only original table
            $this->lockTables('LOCKING table to drain pre-trigger Xacts', false);
        }


        $this->executeSql('COMMITTING', 'COMMIT');

        $this->executeSql('Unlocking after triggers', 'unlock tables');

        $this->executeSql('AUTOCOMMIT ON', 'set session autocommit=1');

        $this->startSlave();

    }


    // Used for creating temp tables for IDs to exclude or IDs to include
    protected function createAndInitTemptable($temptable)
    {
        if ($temptable === self::TEMP_TABLE_IDS_TO_EXCLUDE) {
            $outfile = $this->outfileExcludeIDs;

            $selectinto = "select %s, %s " .
                "from %s " .
                "order by %s into outfile '%s' ";
            $selectinto = sprintf($selectinto,
                self::IDCOLNAME, self::DMLCOLNAME,
                $this->deltastable,
                self::IDCOLNAME, $outfile);

        } else if ($temptable === self::TEMP_TABLE_IDS_TO_INCLUDE) {
            $outfile = $this->outfileIncludeIDs;

            // Select from deltastable that are not in TEMP_TABLE_IDS_TO_EXCLUDE.
            // Use left outer join rather than 'in' subquery for better perf.
            $idcol = $this->deltastable . '.' . self::IDCOLNAME;
            $dmlcol = $this->deltastable . '.' . self::DMLCOLNAME;
            $idcol2 = self::TEMP_TABLE_IDS_TO_EXCLUDE . '.' . self::IDCOLNAME;
            $selectinto = "select %s, %s " .
                "from %s LEFT JOIN %s ON %s = %s " .
                "where %s is null order by %s into outfile '%s' ";
            $selectinto = sprintf($selectinto, $idcol, $dmlcol,
                $this->deltastable, self::TEMP_TABLE_IDS_TO_EXCLUDE,
                $idcol, $idcol2, $idcol2, $idcol, $outfile);
        } else {
            throw new RuntimeException("Invalid param temptable : $temptable");
        }

        $this->executeSql('Selecting ids from deltas to outfile',
            $selectinto);

        $this->cleanupOutfile = $outfile;


        $createtemp = 'create temporary table %s(%s INT,
                                             %s INT,
                                             primary key(%s)) engine=myisam';
        $createtemp = sprintf($createtemp, $temptable,
            self::IDCOLNAME, self::DMLCOLNAME, self::IDCOLNAME);
        $this->executeSql('Creating temp table for ids to exclude',
            $createtemp);

        // read from outfile above into the temp table
        $loadsql = sprintf("LOAD DATA INFILE '%s' INTO TABLE %s(%s, %s)",
            $outfile, $temptable, self::IDCOLNAME, self::DMLCOLNAME);
        $this->executeSql('Loading ids to exclude ', $loadsql);

        unset($this->cleanupOutfile);
        $this->executeUnlink($outfile);
    }

    protected function startSnapshotXact()
    {

        $this->executeSql('starting transaction',
            'START TRANSACTION WITH CONSISTENT SNAPSHOT');
        // any deltas captured so far need to be excluded because they would
        // already be reflected in our snapshot.
        $this->createAndInitTemptable(self::TEMP_TABLE_IDS_TO_EXCLUDE);

    }

    // Generates assignment condition of the form
    // @var1 := col1, @var2 := col2, ....
    protected function assignRangeEndVariables($columns, $variables)
    {
        if (!$columns) {
            return '';
        }
        $count = count($columns);
        $comma = ''; // no comma first time
        $assign = '';
        for ($i = 0; $i < $count; $i++) {
            $assign .= $comma . sprintf('%s := %s', $variables[$i], $columns[$i]);
            $comma = ',';
        }
        return $assign;
    }

    /**
     * Given a list of columns and a list of values (of same length), produce a
     * 'greater than' SQL condition by splitting into multiple conditions.
     * An example result may look like:
     * ((col1 > val1) OR
     * ((col1 = val1) AND (col2 > val2)) OR
     * ((col1 = val1) AND (col2 = val2) AND (col3 > val3)))
     * Which stands for (col1, col2, col3) > (val1, val2, val3).
     * The latter being simple in representation, however MySQL does not utilize
     * keys properly with this form of condition, hence the splitting into multiple
     * conditions.
     * It can also be used for >=, < or <= but we don't need them now.
     */
    protected function getRangeStartCondition($columns, $values,
                                              $comparison_sign = ">")
    {
        $comparison = '';
        $count = count($columns);
        $equality = '';
        $range = '';
        $and = ''; // no AND first time
        $or = '';
        for ($i = 0; $i < $count; $i++) {
            // compare condition for this column
            $range = sprintf(" %s %s %s ", $columns[$i], $comparison_sign,
                $values[$i]);

            // equality comparison for all previous columns
            if ($i > 0) {
                $equality .= $and . sprintf(" %s = %s ", $columns[$i - 1], $values[$i - 1]);
                $and = ' AND ';
            }

            // Note that when $i is 0, both $equality and $and will be empty
            $comparison .= $or . '(' . $equality . $and . $range . ')';
            $or = ' OR ';
        }
        // enclose in ()
        return sprintf('(%s)', $comparison);
    }

    protected function selectFullTableIntoOutfile()
    {

        $selectinto = "select %s " .
            "FROM %s " .
            "INTO OUTFILE '%s.1'";

        $selectinto = sprintf($selectinto, $this->columns,
            $this->qtablenameq, $this->outfileTable);


        $this->executeSql('Selecting full table into outfile',
            $selectinto);

        $this->outfileSuffixStart = 1;
        $this->outfileSuffixEnd = 1;

        $this->executeSql('Committing after generating outfiles', 'COMMIT');
    }

    protected function selectTableIntoOutfile()
    {
        // we want to do the full table dump/load since we can't page
        if ($this->flags & OSC_FLAGS_USE_NEW_PK) {
            return $this->selectFullTableIntoOutfile();
        }

        $whereclause = ''; // first time there is no where clause

        $outfile_suffix = 0;

        $fullCount = $this->executeSql('Counting rows in table', "SELECT COUNT(*) FROM $this->qtablenameq")->fetchColumn();

        $runs = ceil($fullCount / $this->batchsizeLoad);

        $this->logger->notice("Counted: $fullCount rows in table $this->qtablenameq. Batch size: $this->batchsizeLoad. Appx outfile count: $runs");

        $startTime = time();

        $this->outfileSuffixStart = 1;

        do {
			pcntl_signal_dispatch();

            $outfile_suffix++; // start with 1

            $selectinto = "select %s, %s " .
                "FROM %s FORCE INDEX (PRIMARY) %s " .
                "ORDER BY %s LIMIT %d " .
                "INTO OUTFILE '%s.%d'";

            // this gets pk column values into range end variables
            $assign = $this->assignRangeEndVariables($this->pkcolumnarray,
                $this->rangeEndVarsArray);

            $selectinto = sprintf($selectinto,
                $assign, $this->nonpkcolumns, $this->qtablenameq, $whereclause,
                $this->pkcolumns, $this->batchsizeLoad, $this->outfileTable, $outfile_suffix);

            $fileString = sprintf('%s.%d', $this->outfileTable, $outfile_suffix);

            $this->outfileSuffixEnd = $outfile_suffix;

            $this->refreshRangeStart();
            $range = $this->getRangeStartCondition($this->pkcolumnarray, $this->rangeStartVarsArray);
            $whereclause = sprintf(" WHERE %s ", $range);

            if(is_file($fileString) || is_file($fileString . '.loaded'))
            {
                $this->logger->warning("File $fileString already exists from previous run. Skipping...");

                $rowCount = $this->batchsizeLoad;
            }
            else
            {
                try{
                    $stmt = $this->executeSql('Selecting table into outfile: ' . $fileString, $selectinto);
                }
                catch(\Exception $e)
                {
                    $this->executeUnlink($fileString);

                    throw $e;
                }

                $rowCount = $stmt->rowCount();
            }

            $this->logger->notice("Written $rowCount rows to $fileString");

            $this->calculateRuntimeStats($outfile_suffix, $runs, $startTime);

        } while ($rowCount >= $this->batchsizeLoad);

        $this->executeSql('Committing after generating outfiles', 'COMMIT');
    }

    private function calculateRuntimeStats($currentRun, $totalRuns, $startTime)
    {
        $percent = round(($currentRun / $totalRuns) * 100, 1);

        $averageSecondsPerRun = (time() - $startTime) / $currentRun;

        $secondsRemaining = ($totalRuns - $currentRun) * $averageSecondsPerRun;

        $this->logger->notice("File $currentRun of $totalRuns ($percent%)");

        $this->logger->notice(sprintf("Time per run: %s. Time remaining: %s",
            $this->formatSeconds($averageSecondsPerRun),
            $this->formatSeconds($secondsRemaining)));
    }

    private function formatSeconds($seconds)
    {
        $seconds = round($seconds);

        $dtStart = new DateTime("@0");
        $dtSeconds = new DateTime("@$seconds");
        return $dtStart->diff($dtSeconds)->format('%a days, %h hrs, %i mins and %s secs');
    }

    // gets @@datadir into $this->dataDir and returns it as well
    // ensures that datadir ends with /
    protected function getDataDir()
    {
        if (!empty($this->dataDir)) {
            return $this->dataDir;
        }

        $query = 'select @@datadir as dir';

        $result = $this->executeSql('Getting the data directory location from server',$query);

        // we expect only one row
        while ($row = $result->fetch()) {
            // add / at the end but only if it does not already have one
            $this->dataDir = $row['dir'];
            if (empty($this->dataDir)) {
                throw new RuntimeException("Datadir is empty");
            } else {
                // Make sure it ends with / but don't add two /
                $this->dataDir = rtrim($this->dataDir, '/') . '/';
            }

            return $this->dataDir;
        }

        throw new RuntimeException("Query to get datadir returned no rows");
    }


    protected function createCopyTable()
    {
        if(!$this->doesTableExist($this->newtablename))
        {
            $this->executeSql('Creating copy table', "CREATE TABLE $this->newtablename LIKE $this->tablename");
            $this->executeSql('Altering copy table', "ALTER TABLE $this->newtablename $this->altercmd");
        }
        else
        {
            $this->logger->warning("Table $this->newtablename already exists from previous run.");
        }
    }

    // validates any assumptions about PK after the alter
    protected function validatePostAlterPK($primary)
    {
        if (empty($primary)) {
            throw new RuntimeException("No primary key defined in the new table!");
        }

        if ($this->flags & OSC_FLAGS_ACCEPTPK) {
            return;
        }

        if ($this->flags & OSC_FLAGS_USE_NEW_PK) {
            // for this mode we need to ensure that all columns in the new PK
            // are already part of the old version of the table
            foreach ($this->pkcolumnarray as $col) {
                if (!in_array($col, $this->columnarray)) {
                    $error = "You can not create a new PK using new columns.  " .
                        "The columns must already exist in the old table.";
                    throw new RuntimeException($error);
                }
            }
        }

        // check if old PK (available in $this->pkcolumnarry) is a prefix
        // of atleast one index after the alter table.
        // Note that if old PK is (a, b) and after alter table there is an
        // index on (b, a), that is OK as it supports efficient lookups
        // if values of both a and b are provided.
        $pkcount = count($this->pkcolumnarray);
        foreach ($this->indexes as $index) {
            // get an array of index column names
            $colarray = array();
            foreach ($index->columns as $column) {
                $colarray[] = $column->name;
            }

            // get an array slice of 1st pkcount elements
            $prefix = array_slice($colarray, 0, $pkcount);

            $diff = array_diff($this->pkcolumnarray, $prefix);

            // if A and B are equal size and there are no elements in A
            // that are not in B, it means A and B are same.
            if ((count($prefix) === $pkcount) && empty($diff)) {
                return;
            }
        }

        $error = "After alter there is no index on old PK columns. May not " .
            "support efficient lookups using old PK columns. " .
            "Not allowed unless OSC_FLAGS_ACCEPTPK is set.";
        throw new RuntimeException($error);
    }

    // Retrieves info about indexes on copytable
    protected function initIndexes()
    {
        $query = "select * from information_schema.statistics " .
            "where table_name = '%s' and TABLE_SCHEMA = '%s' " .
            "order by INDEX_NAME, SEQ_IN_INDEX";

        $query = sprintf($query, $this->newtablename, $this->dbname);

        $result = $this->conn->query($query);

        // save index info as array
        $this->indexes = array();

        // we are resetting the PK so that it will be used in later steps
        if ($this->flags & OSC_FLAGS_USE_NEW_PK) {
            $this->pkcolumnarray = array();
        }
        $prev_index_name = '';
        $index = null;
        $primary = null;
        while ($row = $result->fetch()) {
            $index_name = oscQuotify($row['INDEX_NAME']);
            $column_name = oscQuotify($row['COLUMN_NAME']);
            if ($prev_index_name != $index_name) {
                // is the 1st column of the index autoincrement column?
                $auto = isset($this->autoIncrement) &&
                    ($column_name === $this->autoIncrement);
                $index = new IndexInfo($this->newtablename, $index_name,
                    $row['NON_UNIQUE'], $auto);
                if ($index->isPrimary()) {
                    $primary = $index;
                }
                $this->indexes[] = $index;
            }
            $index->addColumn($column_name, $row['SUB_PART']);

            if ($this->flags & OSC_FLAGS_USE_NEW_PK && $index->isPrimary()) {
                $this->pkcolumnarray[] = $column_name;
            }
            $prev_index_name = $index_name;
        }

        // re-create these strings with new array
        if ($this->flags & OSC_FLAGS_USE_NEW_PK) {
            $this->initColumnNameStrings();
        }

        $this->validatePostAlterPK($primary);
        $this->joinClauseReplay = $this->getJoinClauseReplay();
    }

    // loads copy table from outfile
    protected function loadCopyTable()
    {
        $totalFiles = $this->outfileSuffixEnd - $this->outfileSuffixStart;

        $suffixStart = $this->outfileSuffixStart;

        $file = 0;

        $startTime = time();

        while ($this->outfileSuffixEnd >= $suffixStart) {
			pcntl_signal_dispatch();

            $file++;

            $filename = sprintf('%s.%d', $this->outfileTable, $suffixStart);

            if ($this->flags & OSC_FLAGS_USE_NEW_PK) {
                $loadsql = sprintf("LOAD DATA INFILE '%s.%d' %s INTO TABLE %s(%s)",
                    $this->outfileTable,
                    $suffixStart,
                    $this->ignoredups,
                    $this->newtablename,
                    $this->columns);
            } else {
                $loadsql = sprintf("LOAD DATA INFILE '%s.%d' %s INTO TABLE %s(%s, %s)",
                    $this->outfileTable,
                    $suffixStart,
                    $this->ignoredups,
                    $this->newtablename,
                    $this->pkcolumns, $this->nonpkcolumns);
            }

            $suffixStart++;

            if(is_file($filename . '.loaded'))
            {
                $this->logger->warning("File $filename has already been loaded in previous run. Skipping...");
                continue;
            }

            $this->executeSql('Loading file into copy', $loadsql);

            // delete file now rather than waiting till cleanup
            // as this will free up space.

            // Create a .loaded file so we don't need to reload this file if the process stops
            touch($filename . '.loaded');

            if (!($this->flags & OSC_FLAGS_NOCLEANUP)) {
                $this->executeUnlink($filename);
            }

            $this->calculateRuntimeStats($file, $totalFiles, $startTime);
        }
    }

    // Generates condition of the form
    // tableA.column1=tableB.column1 AND tableA.column2=tableB.column2 ...
    // If null $columns is passed, it uses $this->pkcolumnarray as array.
    protected function getMatchCondition($tableA, $tableB, $columns = null)
    {
        if ($columns === null) {
            $columns = $this->pkcolumnarray;
        }

        $cond = '';
        $and = ''; // no AND first time
        foreach ($columns as $column) {
            $cond .= $and . sprintf(' %s.%s = %s.%s ',
                    $tableA, $column, $tableB, $column);
            $and = ' AND ';
        }

        $cond .= ' ';

        return $cond;
    }

    // Builds the join clause used during replay.
    // Join condition of the form A.col1 = B.col1 AND A.col2=B.col2 AND ...
    // where A is copytable, B is deltastable AND col1, col2, ... are the
    // PK columns before ALTER.
    protected function getJoinClauseReplay()
    {
        return ($this->getMatchCondition($this->newtablename, $this->deltastable));
    }

    // check that replay command has affected exactly one row
    protected function validateReplay(PDOStatement $statement, $replay_sql)
    {
        $count = $statement->rowCount();
        if ($count > 1 ||
            ($count == 0 && !($this->flags & OSC_FLAGS_ELIMINATE_DUPS))
        ) {
            $error = sprintf('Replay command [%s] affected %d rows instead of 1 row',
                $replay_sql, $count);
            throw new RuntimeException($error);
        }
    }

    // Row has ID that can be used to look up into deltas table
    // to find PK of the row in the newtable to delete
    protected function replayDeleteRow($row)
    {
        $newtable = $this->newtablename;
        $deltas = $this->deltastable;
        $delete = sprintf('delete %s from %s, %s where %s.%s = %d AND %s',
            $newtable, $newtable, $deltas, $deltas, self::IDCOLNAME,
            $row[self::IDCOLNAME],
            $this->joinClauseReplay);
        $stmt = $this->executeSql('Replaying delete row', $delete);
        $this->validateReplay($stmt, $delete);
    }

    // Row has ID that can be used to look up into deltas table
    // to find PK of the row in the newtable to update.
    // New values for update (only non-PK columns are updated) are
    // all taken from deltas table.
    protected function replayUpdateRow($row)
    {
        $assignment = '';
        $comma = ''; // no comma first time
        foreach ($this->nonpkarray as $column) {
            $assignment .= $comma . $this->newtablename . '.' . $column . '=' .
                $this->deltastable . '.' . $column;
            $comma = ', ';
        }

        $newtable = $this->newtablename;
        $deltas = $this->deltastable;
        $update = sprintf('update %s %s, %s SET %s where %s.%s = %d AND %s ',
            $this->ignoredups, $newtable, $deltas, $assignment,
            $deltas, self::IDCOLNAME,
            $row[self::IDCOLNAME],
            $this->joinClauseReplay);
        $this->executeSql('Replaying update row', $update);
        // if original update had old value same as new value, trigger fires
        // and row gets inserted into deltas table. However mysql_affected_rows
        // would return 0 for replay update. So this validation is commented out
        // for now.
        // $this->validateReplay($update);
    }

    // Row has ID that can be used to look up into deltas table
    // to find the row that needs to be inserted into the newtable.
    protected function replayInsertRow($row)
    {
        $insert = sprintf('insert %s into %s(%s) ' .
            'select %s from %s where %s.%s = %d',
            $this->ignoredups, $this->newtablename, $this->columns,
            $this->columns, $this->deltastable, $this->deltastable,
            self::IDCOLNAME, $row[self::IDCOLNAME]);
        $stmt = $this->executeSql('Replaying insert row', $insert);
        $this->validateReplay($stmt, $insert);
    }

    // Copies rows from self::TEMP_TABLE_IDS_TO_INCLUDE to
    // self::TEMP_TABLE_IDS_TO_EXCLUDE
    protected function appendToExcludedIDs()
    {
        $append = sprintf('insert into %s(%s, %s) select %s, %s from %s',
            self::TEMP_TABLE_IDS_TO_EXCLUDE,
            self::IDCOLNAME, self::DMLCOLNAME,
            self::IDCOLNAME, self::DMLCOLNAME,
            self::TEMP_TABLE_IDS_TO_INCLUDE);
        $this->executeSql('Appending to excluded_ids', $append);
    }

    protected function replayChanges($single_xact)
    {
        // create temp table for included ids
        $this->createAndInitTemptable(self::TEMP_TABLE_IDS_TO_INCLUDE);
		pcntl_signal_dispatch();

        $query = sprintf('select %s, %s from %s order by %s',
            self::IDCOLNAME, self::DMLCOLNAME,
            self::TEMP_TABLE_IDS_TO_INCLUDE, self::IDCOLNAME);

        $result = $this->executeSql('Listing changes to replay',$query);
		pcntl_signal_dispatch();

        $i = 0; // iteration count
        $inserts = 0;
        $deletes = 0;
        $updates = 0;

        if (!$single_xact) {
            $this->executeSql('Starting batch xact for replay', 'START TRANSACTION');
        }

        while ($row = $result->fetch()) {
			pcntl_signal_dispatch();

            ++$i;
            if (!$single_xact && ($i % $this->batchsizeReplay == 0)) {
                $this->executeSql('Commiting batch xact for replay', 'COMMIT');
            }

            switch ($row[self::DMLCOLNAME]) {
                case self::DMLTYPE_DELETE :
                    $this->replayDeleteRow($row);
					pcntl_signal_dispatch();
                    $deletes++;
                    break;

                case self::DMLTYPE_UPDATE :
                    $this->replayUpdateRow($row);
					pcntl_signal_dispatch();
                    $updates++;
                    break;

                case self::DMLTYPE_INSERT :
                    $this->replayInsertRow($row);
					pcntl_signal_dispatch();
                    $inserts++;
                    break;

                default :
                    throw new RuntimeException('Invalid DML type');
            }
        }
        if (!$single_xact) {
            $this->executeSql('Commiting batch xact for replay', 'COMMIT');
			pcntl_signal_dispatch();
        }

        $this->appendToExcludedIDs();
		pcntl_signal_dispatch();

        $drop = 'DROP TEMPORARY TABLE ' . self::TEMP_TABLE_IDS_TO_INCLUDE;
        $this->executeSql('Dropping temp table of included ids', $drop);

        $output = sprintf("Replayed %d inserts, %d deletes, %d updates\n", $inserts, $deletes, $updates);
        $this->logger->info($output);
		pcntl_signal_dispatch();
    }

    protected function rowCounts()
    {
        $query1 = sprintf("SELECT count(*) FROM %s", $this->newtablename);
        $query2 = sprintf("SELECT count(*) FROM %s", $this->qtablenameq);

        $count1 = $this->executeSql('Checking old table row count', $query1)->fetchColumn();
        $count2 = $this->executeSql('Checking new table row count', $query2)->fetchColumn();

        if ($count1 !== $count2)
        {
            throw new RuntimeException("Row counts don't match. $count1 => $count2");
        }

        $this->logger->notice("Row counts verified: $count1");

    }

    protected function checksum()
    {
        $query = sprintf("checksum table %s, %s", $this->newtablename, $this->qtablenameq);

        $result = $this->executeSql('Calculating table checksums',$query);

        // we expect only two rows
        $i = 0;
        $checksum = array();
        while ($row = $result->fetch()) {
            $checksum[$i++] = $row['Checksum'];
        }

        if ($checksum[0] != $checksum[1]) {
            throw new RuntimeException("Checksums don't match." . $checksum[0] . "/" . $checksum[1]);
        }

    }

    protected function swapTables()
    {
        $this->stopSlave();

        // without turning off autocommit lock tables is not working
        $this->executeSql('AUTOCOMMIT OFF', 'set session autocommit=0');

        // true means lock both original table and newtable
        $this->lockTables('Locking tables for final replay/swap', true);

        // any changes that happened after we replayed changes last time.
        // true means do them in one transaction.
        $this->replayChanges(true);

        $this->rowCounts();

        // at this point tables should be identical if schema is same
        if ($this->flags & OSC_FLAGS_CHECKSUM) {
            $this->checksum();
        }

        $rename_original = sprintf('alter table %s rename %s', $this->qtablenameq, $this->renametable);
        $this->executeSql('Renaming original table', $rename_original);
        $this->cleanupRenametable = true;

        // if the above command succeeds and the following command fails,
        // we will have:
        // $this->cleanupNewtable set and $this->cleanupRenametable set.
        // In that case we will rename renametable back to original tablename.
        $rename_new = sprintf('alter table %s rename %s', $this->newtablename, $this->qtablenameq);
        $this->executeSql('Renaming new table', $rename_new);
        unset($this->cleanupNewtable);

        $this->executeSql('COMMITTING', 'COMMIT');

        $this->executeSql('Unlocking tables', 'unlock tables');

        $this->executeSql('AUTOCOMMIT ON', 'set session autocommit=1');

        $this->startSlave();

    }

    protected function doesTableExist($tablename)
    {
        $query = sprintf("show tables like '%s'", $tablename);

        $result = $this->executeSql('Checking table exists',$query);

        return $result->rowCount() === 1;
    }

    protected function cleanup($force = false)
    {
        if ($this->flags & OSC_FLAGS_NOCLEANUP) {
            return;
        }

		$this->logger->notice('Cleaning up...');

        $this->executeSql('Unlock tables just in case', 'unlock tables');

        $this->executeSql('Rollback in case we are in xact', 'ROLLBACK');

        // in case we are in autocommit off, turn it on
        $this->executeSql('AUTOCOMMIT ON', 'set session autocommit=1');

        if ($force) {
            $this->cleanupInsertTrigger = true;
            $this->cleanupDeleteTrigger = true;
            $this->cleanupUpdateTrigger = true;
        }
        if (isset($this->cleanupInsertTrigger)) {
			$this->logger->info('Cleaning up insert trigger...');
            $drop = sprintf('drop trigger %s.%s', $this->qdbnameq, $this->insertTrigger);
            $this->executeSql('Dropping insert trigger', $drop, $force);
            unset($this->cleanupInsertTrigger);
        }

        if (isset($this->cleanupDeleteTrigger)) {
			$this->logger->info('Cleaning up delete trigger...');
            $drop = sprintf('drop trigger %s.%s', $this->qdbnameq, $this->deleteTrigger);
            $this->executeSql('Dropping delete trigger', $drop, $force);
            unset($this->cleanupDeleteTrigger);
        }

        if (isset($this->cleanupUpdateTrigger)) {
			$this->logger->info('Cleaning up update trigger...');
            $drop = sprintf('drop trigger %s.%s', $this->qdbnameq, $this->updateTrigger);
            $this->executeSql('Dropping update trigger', $drop, $force);
            unset($this->cleanupUpdateTrigger);
        }

        if ($force) {
            $this->cleanupDeltastable = true;
            $this->cleanupNewtable = true;

            // We need to be careful when dropping renamedtable because
            // during previous run, we may have failed AFTER original
            // table was renamed. If we drop renamed table, we may lose
            // the table.
            if ($this->doesTableExist($this->renametable)) {
                $this->cleanupRenametable = true;
            }
        }

        if (isset($this->cleanupDeltastable)) {
            $this->executeSql('Dropping deltas table', 'drop table ' . $this->deltastable, $force);
            unset($this->cleanupDeltastable);
        }

        // does original table exist
        $orig_table_exists = $this->doesTableExist($this->tablename);

        if (isset($this->cleanupRenametable) && !$orig_table_exists) {
            // rename renametable back to original name.
            $warning = "Original table does not exist but renamed table exists!. " .
                "Must have failed AFTER renaming original table!";
            $this->logger->warning($warning);

            $rename = sprintf('alter table %s rename %s',
                $this->renametable, $this->qtablenameq);
            $this->executeSql('Renaming backup table as original table',
                $rename, $force);
            unset($this->cleanupRenametable);
        } else if (!$orig_table_exists) {
            // PANIC
            throw new RuntimeException("NEITHER ORIGINAL TABLE EXISTS NOR RENAMED TABLE");
        } else if (isset($this->cleanupRenametable)) {
            if ($this->flags & OSC_FLAGS_DROPTABLE) {
                $this->dropTable($this->renametable, $this->conn);
                unset($this->cleanupRenametable);
            }
        }

        if (isset($this->cleanupNewtable)) {
            $this->dropTable($this->newtablename, $this->conn);
            unset($this->cleanupNewtable);
        }

        // in case we stopped slave, start it
        $this->startSlave();

        if (isset($this->cleanupOutfile)) {
            $outfile = $this->cleanupOutfile;
            $this->executeUnlink($outfile, $force);
            unset($this->cleanupOutfile);
        } else if ($force) {
            if (isset($this->outfileIncludeIDs)) {
                $this->executeUnlink($this->outfileIncludeIDs, $force);
            }
            if (isset($this->outfileExcludeIDs)) {
                $this->executeUnlink($this->outfileExcludeIDs, $force);
            }
        }

        if (isset($this->outfileSuffixEnd) && isset($this->outfileSuffixStart)) {
            while ($this->outfileSuffixEnd >= $this->outfileSuffixStart) {
                $filename = sprintf('%s.%d', $this->outfileTable,
                    $this->outfileSuffixStart);
                $this->executeUnlink($filename, $force);
                $this->executeUnlink($filename . '.loaded', $force);
                $this->outfileSuffixStart++;
            }
            unset($this->outfileSuffixEnd);
            unset($this->outfileSuffixStart);
        } else if (isset($this->outfileTable)) {
            $files_wildcard = sprintf('%s.*', $this->outfileTable);
            $files = glob($files_wildcard);
            foreach ($files as $file) {
                $this->executeUnlink($file, $force);
            }
        }

		//one more attempt, in case blobing was impossible due to permissions...
		$x = 0;
		while(true)
		{
			$x++;
			$filename = sprintf('%s.%d', $this->outfileTable, $x);
			if (!@unlink($filename) && !@unlink($filename . '.loaded'))
				break;
		}

        $this->releaseOscLock($this->conn); // noop if lock not held

		$this->logger->notice('Successfully cleaned up');
    }

    public function forceCleanup()
    {
        $this->validateVersion();
        // outfile names for storing copy of table, and processed IDs
        $this->initOutfileNames();

        $this->logger->notice("Running cleanup...");
        $this->cleanup(true);
        $this->logger->notice("Cleaned. Exiting.");
        return true;
    }

    public function execute()
    {
        try {
            $this->validateVersion();

            // outfile names for storing copy of table, and processed IDs
            $this->initOutfileNames();

            if ($this->doesTableExist($this->renametable)) {
                throw new RuntimeException("Please cleanup table $this->renametable left over from prior run.");
            }

            $this->checkLongXact();

            $this->createCopyTable();
            $this->logger->notice("Copy of $this->tablename created at $this->newtablename.");
			pcntl_signal_dispatch();

            // we call init() after the create/alter since we need the new columns
            $this->init();
			pcntl_signal_dispatch();
            $this->createDeltasTable();
			pcntl_signal_dispatch();
            $this->createTriggers();
            $this->logger->notice("Triggers created on $this->tablename");
			pcntl_signal_dispatch();

            $this->startSnapshotXact();
			pcntl_signal_dispatch();
            $this->selectTableIntoOutfile();
            $this->logger->notice("Outfile of $this->tablename complete");
			pcntl_signal_dispatch();

            $this->loadCopyTable();
            $this->logger->notice("Loaded outfile into $this->newtablename");
			pcntl_signal_dispatch();

            $this->replayChanges(false); // false means not in single xact
            $this->logger->notice("Changes against $this->tablename replayed against $this->newtablename");
			pcntl_signal_dispatch();

            $this->swapTables();
			pcntl_signal_dispatch();
            $this->logger->notice("Moved copy $this->newtablename to $this->tablename");
            $this->logger->notice("$this->tablename has been moved to $this->renametable - Don't forget to remove it.");
            $this->cleanup();
        } catch (Exception $e) {
            $this->cleanup();

            throw $e;
        }

        return true;
    }
}
