<?php
$pid = wp_insert_post(['post_title'=>'Dock','post_name'=>'dock','post_content'=>'[scoop_dock location="935"][scoop_grid type="Cabinet"][scoop_grid type="FlavorTub"][scoop_grid type="Batch" history="1"][scoop_grid type="BatchHistory"][scoop_grid type="CabinetWorkflow"][scoop_grid type="Debt"][scoop_grid type="Moving"][scoop_grid type="Tasks"][scoop_grid type="InstockFlavor"][scoop_grid type="ItemPivot"][scoop_grid type="DateActivity"][scoop_grid type="EmptiedLog"][/scoop_dock]','post_status'=>'publish','post_type'=>'page']);
$uid = wp_insert_user(['user_login'=>'smoke','user_pass'=>'smokepass','user_email'=>'smoke@swankyscoop.com','role'=>'administrator']);
flush_rewrite_rules();
echo "dock=$pid user=$uid\n";
