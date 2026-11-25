function wl_ip_add()
{
	var ip = $("#ip").val();
	var life_time = $("#life_time").val();
	if (life_time == "" || isNaN(life_time) || parseInt(life_time) < 0) {
		life_time = 0;
	}
	if (ip == ""){
		return alert("IP不能为空");
	}
	$.ajax({
		type:'post',
		url:'?c=whitelist&a=addBanip',
		data:'ip=' + encodeURIComponent(ip) + '&life_time=' + parseInt(life_time),
		success:function(msg) {
			if (msg != "成功"){
				alert(msg);
			}
			window.location.reload();
		},
		complete: function(msg){
			show_sync();
		}
	});
}
function wl_url_add()
{
	var url = $("#url").val();
	var meth = $("#meth").val();
	if (url == ""){
		return alert("URL不能为空");
	}
	$.ajax({
		type:'post',
		url:'?c=whitelist&a=addBanurl',
		data:'url=' + encodeURIComponent(url) + '&meth=' + encodeURIComponent(meth),
		success:function(msg) {
			if (msg != "成功"){
				alert(msg);
			}
			window.location.reload();
		},
		complete: function(msg){
			show_sync();
		}
	});
}
function wl_ip_del(id)
{
	if (confirm("确定要删除?") == false) {
		return;
	}
	$.ajax({
		type:'post',
		url:'?c=whitelist&a=delBanip',
		data:'id=' + id,
		success:function(msg){
			if (msg != "成功") {
				alert(msg);
			}
			window.location.reload();
		},
		complete: function(msg){
			show_sync();
		}
	});
}
function wl_url_del(id)
{
	if (confirm("确定要删除?") == false) {
		return;
	}
	$.ajax({
		type:'post',
		url:'?c=whitelist&a=delBanurl',
		data:'id=' + id,
		success:function(msg){
			if (msg != "成功") {
				alert(msg);
			}
			window.location.reload();
		},
		complete: function(msg){
			show_sync();
		}
	});
}
function wl_ip_del_all()
{
	if (confirm("确定要清空全部IP白名单?清空后该IP维度将回落到黑名单/CC规则") == false) {
		return;
	}
	$.ajax({
		type:'get',
		url:'?c=whitelist&a=delBanipAll',
		success:function(msg){
			if (msg != "成功") {
				alert(msg);
			}
			window.location.reload();
		},
		complete: function(msg){
			show_sync();
		}
	});
}
function wl_url_del_all()
{
	if (confirm("确定要清空全部URL白名单?") == false) {
		return;
	}
	$.ajax({
		type:'get',
		url:'?c=whitelist&a=delBanurlAll',
		success:function(msg){
			if (msg != "成功") {
				alert(msg);
			}
			window.location.reload();
		},
		complete: function(msg){
			show_sync();
		}
	});
}
function wl_ip_checkon(val)
{
	var data = 'status=' + val;
	functoin_checkon('?c=whitelist&a=switchIp',data);
}
function wl_url_checkon(val)
{
	var data = 'status=' + val;
	functoin_checkon('?c=whitelist&a=switchUrl',data);
}
