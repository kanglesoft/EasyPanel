function sleep(n)
  {
     var   start=new   Date().getTime();
     while(true)
          if(new   Date().getTime()-start> n)
      break;
  } 
function test_node()
{
	$.ajax({
		type:'post',
		dataType:'json',
		url: '?c=manynode&a=getNOde',
		success:function(json) {
			if (json['status'] == 200) {
				for (i=0; i<json['count'];i++) {
					sleep(2);
					var name=null;
					name = json['nodes'][i]['name'];
					$("#tr" + name).html("状态:<span class='ep-loader ep-loader-sm'></span>");
					check_node(name);
				}
			}
		}
	});
}
function check_node(name)
{
	$.ajax({
		type:'get',
		url: '?c=manynode&a=testNode',
		data: 'name=' + name,
		success:function(status) {
			if (status == 200) {
				$("#tr" + name).html("状态:<span class='ep-status is-success'>正常</span>");
			}else{
				$("#tr" + name).html("状态:<span class='ep-status is-error'>异常</span>");
			}
		}
	});
}
$(document).ready(function(){
	//test_node();
});