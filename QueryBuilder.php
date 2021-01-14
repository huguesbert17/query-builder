<?php
/**
 * Created by PhpStorm.
 * User: mertilushugues
 * Date: 2019-05-09
 * Time: 09:01
 */

namespace helper;


use app\Controller;
use app\App;
use app\Session;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PDO;
use PDOStatement;

class QueryBuilder extends \PDO
{
    private $select = [];
    private $insert = [];
    private $onDuplicate = [];
    private $update = [];
    private $perPage = 10;
    private $set = [];
    private $into;
    private $from;
    private $where = [];
    private $groupBy = [];
    private $orderBy = [];
    private $innerJoin = [];
    private $join = [];
    private $leftJoin = [];
    private $rightJoin = [];
    private $crossJoin = [];
    private $having;
    private $limit;
    private $params = [];
    private $union;
    private $paginate;
    /**
     * @var int
     */
    private $total;

    /**
     * @param $min
     * @param bool $max
     * @return QueryBuilder
     */
    public function limit($min, $max = false): self
    {
        if ($max) {
            $this->limit = "$min, $max";
        }else{
            $this->limit = $min;
        }
        return $this;
    }

    /**
     * @param $items
     * @return $this
     */
    public function paginate($items): self
    {
        $this->paginate = $items;
        return $this;
    }

    /**
     * @param string ...$columns
     * @return $this
     */
    public function select(string ...$columns): self
    {
        $this->select = array_merge($this->select, $columns);
        return $this;
    }

    /**
     * @param $tab
     * @param bool $alias
     * @return QueryBuilder
     */
    public function update($tab, $alias = false): self
    {
        if ($alias){
            $this->update[$alias] = $tab;
        }else {
            $this->update = $tab;
        }
        return $this;
    }

    /**
     * @param string ...$columns
     * @return QueryBuilder
     */
    public function set(string ...$columns): self
    {
        $this->set = array_merge($this->set, $columns);
        return $this;
    }

    /**
     * @param string ...$columns
     * @return QueryBuilder
     */
    public function insert(string ...$columns): self
    {
        $this->insert = array_merge($this->insert, $columns);
        return $this;
    }

    /**
     * @param array $updating
     * @return $this
     * Associative array with column to be updated as key and new value as value
     * or just an array with int index as column that will be updated with sent values
     */

    public function onDuplicate(array $updating)
    {
        $val = '';
        foreach ($updating as $key => $value){
            if (is_array($key)) {
                $val .= $key . ' = ' . $value . ', ';
            }else{
                $val .= $value . ' = VALUES(' . $value . '), ';
            }
        }
        $this->onDuplicate = trim($val, ', ');
        return $this;
    }

    /**
     * @param $query
     * @param false $is_union_all
     * @return $this
     */
    public function union($query, $is_union_all = false):self
    {
        if ($is_union_all) {
            $this->union = ' UNION ALL';
        }else{
            $this->union = ' UNION';
        }
        $this->union .= ' (' . $query . ' ) ';
        return $this;
    }

    /**
     * @param $tab
     * @param string|null $alias
     * @return QueryBuilder
     */
    public function from($tab, string $alias = null): self
    {
        if ($alias){
            $this->from[$alias] = $tab;
        }else{
            $this->from[] = $tab;
        }
        return $this;
    }

    /**
     * @param $tab
     * @return QueryBuilder
     */
    public function into($tab): self
    {
        $this->into[] = $tab;
        return $this;
    }

    /**
     * @param string ...$cond
     * @return QueryBuilder
     */
    public function where(string ...$cond): self
    {
        $this->where = array_merge($this->where, $cond);
        return $this;
    }

    /**
     * @param string ...$columns
     * @return QueryBuilder
     */
    public function groupBy(string ...$columns): self
    {
        $this->groupBy = $columns;
        return $this;
    }

    public function having(string ...$conditions): self
    {
        $this->having = $conditions;
        return $this;
    }

    public function orderBy(string ...$row_and_how): self
    {
        $this->orderBy = array_merge($row_and_how);
        return $this;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        if ($this->select) {
            $start = ['SELECT'];
            if ($this->select) {
                $start[] = join(', ', $this->select);
            } else {
                $start[] = '*';
            }
            $start[] = 'FROM';
            $start[] = $this->buildFrom();
        }elseif ($this->insert){
            $start = ['INSERT INTO'];
        }elseif ($this->update && !$this->innerJoin){
            $start = ['UPDATE'];
            $start[] = $this->buildUpdate();
            $start[] = 'SET';
            $start[] = join(', ', $this->set);
        }

        if ($this->innerJoin && $this->update){
            $start = ['UPDATE'];
            $start[] = $this->buildUpdate();
            foreach ($this->innerJoin as $key=>$value){
                if (is_string($key)){
                    $start[] = "INNER JOIN $key ON $value";
                }
            }
            $start[] = 'SET';
            $start[] = join(', ', $this->set);
        }

        if ($this->into){
            foreach ($this->into as $key => $value){
                if (is_string($key)){
                    $start[] = "$value as $key";
                }else{
                    $start[] = $value;
                }
            }
            $start[] = '(';
            $start[] = join(', ', $this->insert);
            $start[] = ') VALUES (';
            $columns = '';

            $params = [];
            if (isset($this->params[0]) && is_array($this->params[0])){
                foreach ($this->params as $k => $p){
                    $params[$k] = $this->params[$k];
                }
                $this->params = $params;
            }

            if (isset($this->params[0]) && is_array($this->params[0])) {
                for ($i = 0; $i < count((array)$this->params[0]); $i++) {
                    $columns .= '?, ';
                }
            }else{
                for ($i = 0; $i < count($this->params); $i++) {
                    $columns .= '?, ';
                }
            }
            $start[] = trim($columns, ', ');

            //$start[] = join(', ', $this->insert);
            $start[] = ')';
        }


        /**
         * Build junctions for table
         */

        //Inner join
        if ($this->innerJoin && !$this->update){
            foreach ($this->innerJoin as $key=>$value){
                if (is_string($key)){
                    $start[] = "INNER JOIN $key ON $value";
                }
            }
        }

        //Left join
        if ($this->leftJoin){
            foreach ($this->leftJoin as $key=>$value){
                if (is_string($key)){
                    $start[] = "LEFT JOIN $key ON $value";
                }
            }
        }

        //Right join
        if ($this->rightJoin){
            foreach ($this->rightJoin as $key=>$value){
                if (is_string($key)){
                    $start[] = "RIGHT JOIN $key ON $value";
                }
            }
        }

        //Cross join
        if ($this->crossJoin){
            foreach ($this->crossJoin as $key=>$value){
                if (is_string($key)){
                    $start[] = "CROSS JOIN $key ON $value";
                }
            }
        }

        //Simple join
        if ($this->crossJoin){
            foreach ($this->join as $key=>$value){
                if (is_string($key)){
                    $start[] = "JOIN $key ON $value";
                }
            }
        }


        //Where clause as condition
        if (!empty($this->where)){
            $start[] = 'WHERE';
            $start[] = '(' . join(') AND (', $this->where) . ')';
        }
        //Union
        if ($this->union){
            $start[] = $this->union;
        }
        if ($this->groupBy){
            $start[] = 'GROUP BY';
            $start[] = join(', ', $this->groupBy);
        }
        if ($this->having){
            $start[] = 'HAVING';
            $start[] = join(', ', $this->having);
        }
        if (count($this->orderBy)) {
            $start [] = 'ORDER BY';
            $start [] = join(' ', $this->orderBy);
        }
        if ($this->limit){
            $start[] = 'LIMIT';
            $start[] = $this->limit;
        }

        if ($this->onDuplicate){
            $start[] = 'ON DUPLICATE KEY UPDATE';
            $start [] = $this->onDuplicate;
        }

        return join(' ', $start);
    }

    public function buildUpdate()
    {
        $update = [];
        foreach ($this->update as $key => $value){
            if (is_string($key)){
                $update[] = "$value as $key";
            }else{
                $update[] = $value;
            }
        }
        return join(' ', $update);
    }

    public function leftJoin($tab, $condition, $alias = false): self
    {
        $alias = trim($alias);
        if ($alias){
            $this->leftJoin[join(' ', [$tab, $alias])] = $condition;
        }else{
            $this->leftJoin[$tab] = $condition;
        }
        return $this;
    }

    public function join($tab, $condition, $alias = false): self
    {
        $alias = trim($alias);
        if ($alias){
            $this->join[join(' ', [$tab, $alias])] = $condition;
        }else{
            $this->join[$tab] = $condition;
        }
        return $this;
    }

    public function innerJoin($tab, $condition, $alias = false): self
    {
        $alias = trim($alias);
        if ($alias){
            $this->innerJoin[join(' ', [$tab, $alias])] = $condition;
        }else {
            $this->innerJoin[$tab] = $condition;
        }
        return $this;
    }

    public function rightJoin($tab, $condition, $alias = false): self
    {
        $alias = trim($alias);
        if ($alias){
            $this->rightJoin[join(' ', [$tab, $alias])] = $condition;
        }else {
            $this->rightJoin[$tab] = $condition;
        }
        return $this;
    }

    public function crossJoin($tab, $condition, $alias = false): self
    {
        $alias = trim($alias);
        if ($alias){
            $this->crossJoin[join(' ', [$tab, $alias])] = $condition;
        }else {
            $this->crossJoin[$tab] = $condition;
        }
        return $this;
    }

    /**
     * @return string
     */
    private function buildFrom(): string
    {
        $from = [];
        foreach ($this->from as $key => $value){
            if (is_string($key)){
                $from[] = "$value as $key";
            }else{
                $from[] = $value;
            }
        }
        return join(', ', $from);
    }

    /**
     * @param array $params
     * @return array|bool|PDOStatement
     */
    private function execute($params = [])
    {
        $query = $this->__toString();
        if ($this->params){
            $pdoStatement = $this->prepare($query);
            try {
                $pdoStatement->execute($this->params);
            }catch (\PDOException $e){
                $log = new Logger('pdo');
                $log->pushHandler(new StreamHandler(LOG_PATH. 'pdo.log', Logger::DEBUG));
                $log->pushHandler(new FirePHPHandler());

                // add records to the log
                $log->error($e->getMessage());
                $pdoStatement = false;
            }

            $this->reset();
            return $pdoStatement;
        }else{
            $this->reset();
            return $this->query($query);
        }
    }

    public function save()
    {
        if(count($this->update)) {
            $stmt = $this->execute($this->params);
            return $stmt->rowCount();
        }else{
            if(isset($this->params[0]) && is_array($this->params[0])){
                $params = '';
                $IDs = [];
                foreach ($this->params as $p){
                    $params .= '(' . join(',', $p) . '), ';
                }

                $pdoStatement = $this->prepare($this->__toString());

                foreach ($this->params as $k => $p) {
                    $pdoStatement->execute($p);
                    array_push($IDs, $this->lastInsertId());
                }
                $this->reset();
                if (count($IDs)){
                    Session::setHeaderCode(201);
                    return $IDs;
                }else{
                    Session::setHeaderCode(204);
                    return -1;
                }
            }else{
                $this->execute($this->params);
                if ($this->lastInsertId()){
                    Session::setHeaderCode(201);
                    return $this->lastInsertId();
                }else{
                    Session::setHeaderCode(204);
                    return -1;
                }
            }
        }
    }

    /**
     * @param false $fetch_style
     * @return array
     */
    public function getAll($fetch_style = false)
    {
        if ($this->paginate) { //If user ask for pagination
            $key = end($this->from);
//            $key = '';
//            foreach($this->from as $k => $v){ //We will give index of the result the FROM table's name of the query as name,
//                if (!is_numeric($k)){ //If check if from array key is numeric
//                    $key = $v;
//                }
//            }
            $perPage = $this->paginate; //The amount of item asked to be returned
            $questionMarks = substr_count($this->__toString(), '?'); //As we will only count, we don't need ? in select, we just need which in where and other
            $start = (Controller::$page - 1) * $perPage;

            $select = $this->select; //We keep previous select columns
            $this->select = [' COUNT(*) as total_count']; //We replace previous column with a select count(*)
            $query = $this->__toString(); //We het the new built string
            $params = array_slice($this->params, $questionMarks-1, count($this->params)); //We also remove ? related params in params[], comme les ? dans select sont en premier, we trim the array from the last ? to the legth of the array

            $qt = $this->prepare($query); //We prepare the count query
            $qt->execute($params);//And execute it

            //This is the total with select count(*)
            $total_count = 0;
            $tempQt = $qt->fetch();
            if (isset($tempQt->total_count)){
                $total_count = $tempQt->total_count;
            }

            $this->select = $select; //We reassign the initial values of the asked columns
            $req[$key] = $this->execute($this->__toString() . " LIMIT $start, $perPage")->fetchAll(); //We execute the original query and we trim it as user asked, we alias an array with the key of the FROM table's name
            $this->total = count($req[$key]); //Total shrink item

            if ($this->total > $perPage) {//We check if returned results is > to value item per page
                $pages = ceil($this->total / $perPage); //If yes, we divide them and round them up to get the amount of page
            }else{//If no
                $pages = $this->total;
            }
            $ctrl = strtolower(str_replace('Controller', '', App::$router->getController()));
            $page = [];
            if ($pages > 1) {
                for ($i = 0; $i < $this->total; $i++) {
                    $page[$i] = '/' . $ctrl . '/' . strtolower(App::$router->getAction()) . '?page=' . ($i + 1);
                }
            }
            $pk = ($key == 'pages') ? 'pageLinks' : 'pages';
            $req[$pk] = $this->buildPages([
                'total_row' => (int)$this->total,
                'total_count' => $total_count,
                'total_page' => $pages,
                'pages' => $page,
                'cur_page' => $perPage*Controller::$page,
                'start' => $start,
                'p' => Controller::$page
            ]);
            return $req;
        }else{
            if ($fetch_style){
                return $this->execute($this->params)->fetchAll($fetch_style);
            }
            return $this->execute($this->params)->fetchAll();
        }
    }

    /**
     * @param $pages
     * @return string
     * Create predesigned page number
     */
    private function buildPages($pages = []){
        $it = '';
        if ($pages){
            $previous = (($pages['p'] - 1) > 0) ? 'data-toggled="' . ($pages['p'] - 1) . '"' : '';
            $next = (($pages['p']) < $pages['total_page']) ? 'data-toggled="' . ($pages['p'] + 1) . '"' : '';

            $it .= '<div class="cel-12">';

            $it .= '<div class="page-number-wrapper">';
            $realStart = ($pages['total_row'] == 0) ? 0 : $pages['start'] + 1;
            $it .= '<span class="database-row-count"><span id="start">Showing ' . ($realStart) . '</span> to <span id="end">' . ($pages['start'] + $pages['total_row']) . '</span> of <span id="total_row">' . $pages['total_count'] . (($pages['total_count'] > 1) ? ' items' : ' item') . '</span></span>';
            $it .= '<span class="tooltip next-previous" title="Previous page" ' . $previous . '><i class="mdi mdi-arrow-left-box"></i></span>';

            $class = "next-previous";
            if ($pages['total_row'] < $pages['total_count']) {
                $class = "next-previous end";
            }
            $it .= '<span class="tooltip ' . $class . '" title="Next page" ' . $next . '><i class="mdi mdi-arrow-right-box"></i></span>';
            $it .= '</div>';

            $it .= '</div>';
        }
        return $it;
    }


    /**
     * @return mixed
     */
    public function getOne()
    {
        return $this->execute($this->params)->fetch();
    }

    /**
     * Reset all fields
     */
    private function reset() {
        foreach (get_class_vars(get_class($this)) as $var => $def_val){
            $this->$var = $def_val;
        }
    }

    /**
     * @param string ...$params
     * @return QueryBuilder
     */
    public function params(string ...$params): self
    {
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    /**
     * @param string ...$params
     * @return QueryBuilder
     */
    public function param($params = []): self
    {
        $this->params = array_merge($this->params, $params);
        return $this;
    }

    public function _query()
    {
        return $this->__toString();
    }
}