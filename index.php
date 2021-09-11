<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

//	http_response_code(400);
//	return;

session_start();
header('Content-Type: application/json');

$password_salt = 'school-app';

$db_host="localhost";
$db_port=63306;
$db_socket="";
$db_user="schooldb";
$db_password="schooldb";
$db_name="schooldb";

$CON = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port, $db_socket) or err('Could not connect to the database server' . mysqli_connect_error());

// add default admin
// $params = array('login'=>$db_user, 'password'=>md5($password_salt . $db_password), 'name'=>'Default Admin', 'family'=>NULL, 'avatar'=>NULL);
// user_add('admin');

$USER_ID = key_exists('user_id', $_SESSION) ? intval($_SESSION['user_id']) : 0;

if ($USER_ID <= 0) {
    if (
        !key_exists('login', $_GET) ||
        !key_exists('password', $_GET) ||
        !key_exists('seed', $_SESSION) ||
        !($USER_ID = user_login($_GET['login'], $_GET['password'], $_SESSION['seed']))
    ) {
        $_SESSION['seed'] = $seed = md5(rand());
        die(json_encode(array(
            'result' => 0,
            'seed' => $seed
        )));
    }
    else {
        $_SESSION['user_id'] = $USER_ID;
    }
}
unset($_SESSION['seed']);

$q = $CON->query("SELECT COUNT(*) FROM `admin` WHERE `user_person_id` = $USER_ID;");
$is_admin = ($q && intval($q->fetch_row()[0]) === 1);
$q = $CON->query("SELECT COUNT(*) FROM `parent` WHERE `user_person_id` = $USER_ID;");
$is_parent = ($q && intval($q->fetch_row()[0]) === 1);

$do = @array_keys($_GET)[0];
$params = @json_decode(@file_get_contents("php://input"), TRUE);
$r = NULL;

try {
    switch ($do) {
        case 'login':
        case 'user_info':
            $r = user_info();
            break;

        case 'get_dump':
            check_admin();
            $r = get_dump();
            break;

        case 'person_delete':
            check_admin();
            $r = person_delete();
            break;

        case 'person_update':
            check_admin();
            $r = person_update();
            break;

        case 'user_update':
            check_admin();
            $r = user_update();
            break;

        case 'admin_add':
            check_admin();
            $r = user_add('admin');
            break;

        case 'parent_add':
            check_admin();
            $r = user_add('parent');
            break;

        case 'pupil_add':
            check_admin();
            $r = pupil_add();
            break;

        case 'pupil_bind':
            check_admin();
            $r = pupil_bind();
            break;

        case 'pupil_unbind':
            check_admin();
            $r = pupil_unbind();
            break;

        case 'class_delete':
            check_admin();
            $r = class_delete();
            break;

        case 'class_update':
            check_admin();
            $r = class_update();
            break;

        case 'class_add':
            check_admin();
            $r = class_add();
            break;

        default:
            err("Invalid request");
            break;
    }
}
catch (Throwable $e) {
    $msg = $e->getMessage();
    err("Error at $do: $msg");
}

$CON->close();

die(json_encode(array(
    'result' => 1,
    'data' => $r
    /* , 'debug' => array(
        'get' => $_GET,
        'params' => $params
    ) */
), JSON_NUMERIC_CHECK));

function check_admin()
{
    global $is_admin;
    if ($is_admin) return;
    err("Invalid request");
}

function check_parent()
{
    global $is_parent;
    if ($is_parent) return;
    err("Invalid request");
}

function user_login(string $login, string $password, string $seed): int
{
    global $CON;
    $login = esc($login);
    $password = esc($password);
    $seed = esc($seed);
    $q = $CON->query("SELECT person_id FROM `user` WHERE login = $login AND MD5(CONCAT(password, $seed)) = $password LIMIT 1;");
    if ($q && $q->num_rows === 1) {
        $r = $q->fetch_assoc();
        return intval($r['person_id']);
    }
    return 0;
}

function user_info(): ?array
{
    global $CON, $USER_ID;
    $q = $CON->query("SELECT u.login, p.name, p.family, p.avatar, IF(a.user_person_id IS NULL, 0, 1) admin, IF(p2.user_person_id IS NULL, 0, 1) parent FROM person p INNER JOIN `user` u ON u.person_id = p.id LEFT JOIN admin a ON a.user_person_id = p.id LEFT JOIN  parent p2 ON p2.user_person_id = p.id WHERE p.id = $USER_ID LIMIT 1;");
    if ($q && $q->num_rows === 1) {
        return $q->fetch_assoc();
    }
    die("Wrong user id for user_info()");
}

function get_dump(): ?array
{
    global $CON;
    return array(
        'admins' =>
            ($q = $CON->query("SELECT u.login, p.name, p.family, p.avatar FROM person p INNER JOIN `user` u ON u.person_id = p.id INNER JOIN admin a ON a.user_person_id = p.id;"))
                ? $q->fetch_all(MYSQLI_ASSOC) : NULL,
        'parents' =>
            ($q = $CON->query("SELECT u.login, p.name, p.family, p.avatar FROM person p INNER JOIN `user` u ON u.person_id = p.id INNER JOIN parent a ON a.user_person_id = p.id;"))
                ? $q->fetch_all(MYSQLI_ASSOC) : NULL,
        'classes' =>
            ($q = $CON->query("SELECT `id`, `year`, `letter` FROM `class`;"))
                ? $q->fetch_all(MYSQLI_ASSOC) : NULL,
        'pupils' =>
            ($q = $CON->query("SELECT pp.class_id class, p.name, p.family, p.avatar FROM person p INNER JOIN `pupil` pp ON pp.person_id = p.id;"))
                ? $q->fetch_all(MYSQLI_ASSOC) : NULL,
        'duties' =>
            ($q = $CON->query("SELECT `id`, `date`, `class_id` FROM `duty`;"))
                ? $q->fetch_all(MYSQLI_ASSOC) : NULL
    );
}

/**
 * @param string $table
 * @param string $pk
 * @return bool
 * @throws Exception
 */
function _update(string $table, string $pk): bool
{
    global $CON, $password_salt;
    $id = param_int('id');
    $fields = param('fields');
    $a = array();
    foreach ($fields as $field) {
        foreach ($field as $key => $value) {
            if ($key === 'password') {
                $value = md5($password_salt . param('password'));
            }
            $a[] = "`$key` = " . esc($value);
        }
    }
    $args = join(', ', $a);
    /** @noinspection SqlResolve */
    $r = $CON->query("UPDATE `$table` SET $args WHERE `$pk` = $id LIMIT 1;");
    return ($r && $CON->affected_rows === 1);
}

/**
 * @return bool
 * @throws Exception
 */
function person_delete() : bool
{
    global $CON;
    $id = param_int('id');
    return $CON->query("DELETE FROM `person` WHERE `id` = $id;");
}

/**
 * @return bool
 * @throws Exception
 */
function person_update(): bool
{
    return _update('person', 'id');
}

/**
 * @return bool
 * @throws Exception
 */
function user_update(): bool
{
    return _update('user', 'person_id');
}

/**
 * @param string $subtable
 * @return int
 * @throws Exception
 */
function user_add(string $subtable) : int
{
    global $CON, $password_salt;
    $login = esc(param('login'));
    $password = esc(md5($password_salt . param('password')));
    $name = esc(param('name'));
    $family = esc(param('family', TRUE));
    $avatar = esc(param('avatar', TRUE));
    /** @noinspection SqlResolve */
    if (
        $CON->begin_transaction() &&
        $CON->query("INSERT INTO `person` (`name`, `family`, `avatar`) VALUES ($name, $family, $avatar);") &&
        ($person_id = $CON->insert_id) &&
        $CON->query("INSERT INTO `user` (`person_id`, `login`, `password`) VALUES ($person_id, $login, $password);") &&
        $CON->query("INSERT INTO `$subtable` (`user_person_id`) VALUES ($person_id);") &&
        $CON->commit()
    ) return $person_id;
    $CON->rollback();
    return 0;
}

/**
 * @return int
 * @throws Exception
 */
function pupil_add() : int
{
    global $CON;
    $class_id = param_int('class');
    $name = esc(param('name'));
    $family = esc(param('family', TRUE));
    $avatar = esc(param('avatar', TRUE));
    if (
        $CON->begin_transaction() &&
        $CON->query("INSERT INTO `person` (`name`, `family`, `avatar`) VALUES ($name, $family, $avatar);") &&
        ($person_id = $CON->insert_id) &&
        $CON->query("INSERT INTO `pupil` (`person_id`, `class_id`) VALUES ($person_id, $class_id);") &&
        $CON->commit()
    ) return $person_id;
    $CON->rollback();
    return 0;
}

/**
 * @return bool
 * @throws Exception
 */
function pupil_bind() : bool
{
    global $CON;
    $id = param_int('id');
    $parent = param_int('parent');
    return $CON->query("INSERT INTO `pupil_has_parent` (`pupil_person_id`, `parent_user_person_id`) VALUES ($id, $parent);");
}

/**
 * @return bool
 * @throws Exception
 */
function pupil_unbind() : bool
{
    global $CON;
    $id = param_int('id');
    $parent = param_int('parent');
    return $CON->query("DELETE FROM `pupil_has_parent` WHERE `pupil_person_id` = $id AND `parent_user_person_id` = $parent;");
}

/**
 * @return bool
 * @throws Exception
 */
function class_delete() : bool
{
    global $CON;
    $id = param_int('id');
    return $CON->query("DELETE FROM `class` WHERE `id` = $id;");
}

/**
 * @return bool
 * @throws Exception
 */
function class_update(): bool
{
    return _update('class', 'id');
}

/**
 * @return int
 * @throws Exception
 */
function class_add() : int
{
    global $CON;
    $year = param_int('year');
    $letter = esc(param('letter'));
    if (
        $CON->query("INSERT INTO `class` (`year`, `letter`) VALUES ($year, $letter);")
    ) return $CON->insert_id;
    return 0;
}


/**
 * @param string $name
 * @param false $allow_empty
 * @return int
 * @throws Exception
 */
function param_int(string $name, $allow_empty = FALSE): int
{
    global $params;
    $result = intval(@$params[$name]);
    if (!$allow_empty && !$result) throw new Exception("Required parameter '$name' is empty");
    return $result;
}

/**
 * @param string $name
 * @param false $allow_empty
 * @return mixed
 * @throws Exception
 */
function param(string $name, $allow_empty = FALSE)
{
    global $params;
    $result = @$params[$name];
    if (!$allow_empty && empty($result)) throw new Exception("Required parameter '$name' is empty");
    return $result;
}

function esc($str): string
{
    global $CON;
    if ($str === NULL) {
        return 'NULL';
    }
    $str = $CON->escape_string($str);
    return "'$str'";
}

function err(string $msg, int $code = -1)
{
    global $CON;
    @$CON->close();
    die("{ \"result\" : $code, \"error\" : \"$msg\" }");
}

