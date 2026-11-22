<html>
<head>
<meta charset="utf-8">
<title>Test1</title>
<link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
<!--link rel="stylesheet" href="css/style.css"-->
<script language="javascript" type="text/javascript" src="js/jQuery.js"></script> 
<script src="https://www.google.com/recaptcha/api.js"></script>
<script type="text/javascript" src="https://sale.moipass.ru/external.script.js?rand=<? echo rand(); ?>"></script>
</head>
<body>

<div class="r2_widget container">
  <h1 id="widget_loading">Загружаем виджет...</h1>
  <div class="widget_place" id="widget_place">
  <form id="check_form">
	  <label>Номер брони<input type=text id="order_id"></label><br><br>
	  <label>Код подтверждения<input type=text id="check_code"></label><br><br>
	  <input type=submit text="Проверить" onclick="checkCode()">

  </form>
	  <button class="g-recaptcha" 
        data-sitekey="6LfWiZUdAAAAAKz-2TC_axkM4nZs_LRoTHyQefJD" 
        data-callback='onSubmit' 
        data-action='submit'>Submit</button>
	</div>
</div>
		
<script>

function checkCode()
{
	order_id = document.getElementById("order_id").value;
	check_code = document.getElementById("check_code").value;
	vis = document.getElementById("widget_place");
	vis.innerHTML = " ";
	
	$.getScript( "t2.php?order_id="+order_id+"&check_code="+check_code )
	  .done(function( script, textStatus ) {
		console.log( textStatus );
		RunJS();
		//your remaining code
	  })
	  .fail(function( jqxhr, settings, exception ) {
		console.log( "fail load" );
		//script fail warning if you want it
	});
}


function onSubmit(token) {
 document.getElementById("check_form").submit();
}
   
   
</script>
<?php
?>

</body>
</html>