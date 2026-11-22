<?php

function showWidget()
{
echo "function RunJS() {
      var ResetCartValue = true;
      WPInit({ height: \"550\", id: 9, place: \".widget_place\"});
      WPOpen({
        resetcart: ResetCartValue,
        preorder : [ {
          id: 4524,
          date: '".date("d.m.Y")."',
          count: 1
        } ],
        forcedate: '".date("d.m.Y")."',
        cartmaxitems: 1,
        onlytab: \"service\",
        nocalendar: true,
        nopromo: true,
        tariffsid: [ 4524 ]
      });
}

WPChange = function (response) {
        if (response.status == \"started\") $(\"#widget_loading\").hide();
      };
";
}

function showDummy()
{
	echo "function RunJS() {
		vis = document.getElementById(\"widget_place\");
	vis.innerHTML = \"Невозможно проверить бронь, попробуйте ещё раз\";
	}";
}

  if(!isset($_GET['order_id']) || !isset($_GET['check_code']))
  {
	showDummy();
//    echo -1;
    die;
  }

  $order_id = $_GET['order_id'];
  $check_code = $_GET['check_code'];

  if(!is_numeric($order_id) || !is_numeric($check_code))
  {
	showDummy();
//    echo -2;
    die;
  }



$serverName = "192.168.12.215"; //если instance и port стандартные, то можно не указывать
$connectionInfo = array("UID" => "backup", "PWD" => "backup", "Database"=>"CardService","CharacterSet" =>"UTF-8" /*,"ConnectionPooling" => "1", "MultipleActiveResultSets"=>'0'*/);
$conn = sqlsrv_connect( $serverName, $connectionInfo);
 
if( !$conn )
{
	showDummy();
//     echo -3;
     die;
}



  $sql = "select COUNT(*) as cnt from [CardService].[dbo].[Queue] as a left join [CardService].[dbo].[Prepayment] as b on a.QueueId = b.Prepayment_QueueId where a.enumerator = $order_id and b.enumerator = $check_code";

  $stmt = sqlsrv_query( $conn, $sql, array(), array( "Scrollable" => SQLSRV_CURSOR_STATIC ) );
  if( $stmt === false)
{
	showDummy();
//     echo -4;
     die;
}

  $row = sqlsrv_fetch_array( $stmt, SQLSRV_FETCH_ASSOC);
  $cnt = $row['cnt'];
  if($cnt>0)
	  showWidget();
  else
	  showDummy();
  //echo $cnt;
  sqlsrv_free_stmt( $stmt);
  sqlsrv_close( $conn);


?>
