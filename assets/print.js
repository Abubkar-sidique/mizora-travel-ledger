// Print the same statement markup; print CSS controls the paper layout.
addEventListener('load',function(){
 requestAnimationFrame(function(){setTimeout(function(){window.print();},150);});
},{once:true});