<?php
// Файл по умолчанию при доступе к хосту. Определяет с какого IP идёт запрос и может выдавать разные HTML в зависимости от этого
// Нужен для тестирования новых версий

$ip = $_SERVER['REMOTE_ADDR'];
switch($ip)
{

//Test-VM
	case "10.202.8.213": 
		include "test.html";
		break;

//sysadmin
	case "10.202.77.24": 
		include "test.html";
		break;

/*
//wardrobe-tv
case "10.202.33.196": 
		include "test.html";
		break;
*/
/*
//left-tv
case "10.202.33.190": 
		include "test.html";
		break;
*/

//right-tv
	case "10.202.33.192": 
		include "test.html";
		break;

/*
//dopkassa-tv
	case "10.202.33.193": 
		include "test.html";
		break;
*/

/*
//external-tv
	case "10.202.33.195": 
		include "test.html";
		break;
*/


	default:
		include "index.html";
		break;
}
?>