document.addEventListener('DOMContentLoaded',function(){
var path=window.location.pathname;
if(!path.includes('/faculty/course_edit.php'))return;
var params=new URLSearchParams(window.location.search);
var id=params.get('id');
var step=params.get('step');
if(!id||(step!=='4'&&step!=='8'))return;
var body=document.querySelector('.content-body');
if(!body)return;
var box=document.createElement('div');
box.className='alert alert-info';
box.innerHTML='Assessment rubrics and performance tasks must be completed before approval. <a class="btn btn-sm btn-primary" href="assessment_details.php?id='+encodeURIComponent(id)+'">Open assessment details</a>';
body.insertBefore(box,body.firstChild);
});
