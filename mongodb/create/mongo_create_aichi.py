#! /usr/bin/python
# -*- coding: utf-8 -*-
#
#	mongo_create.py
#
#					Jul/01/2018
#
# -------------------------------------------------------------
import	sys
import pymongo
#
sys.path.append ('/var/www/data_base/common/python_common')
from text_manipulate import dict_append_proc
from mongo_manipulate import dict_to_mongo_proc
#
# -------------------------------------------------------------
def	data_prepare_proc ():
	dict_aa = {} 
#
	dict_aa = dict_append_proc(dict_aa,'t2381','名古屋',58761,'2003-4-30')
	dict_aa = dict_append_proc(dict_aa,'t2382','豊橋',47295,'2003-5-10')
	dict_aa = dict_append_proc(dict_aa,'t2383','岡崎',21674,'2003-6-14')
	dict_aa = dict_append_proc(dict_aa,'t2384','一宮',83612,'2003-9-9')
	dict_aa = dict_append_proc(dict_aa,'t2385','蒲郡',42391,'2003-8-4')
	dict_aa = dict_append_proc(dict_aa,'t2386','常滑',35987,'2003-1-21')
	dict_aa = dict_append_proc(dict_aa,'t2387','大府',81246,'2003-7-23')
	dict_aa = dict_append_proc(dict_aa,'t2388','瀬戸',25791,'2003-10-26')
	dict_aa = dict_append_proc(dict_aa,'t2389','犬山',54139,'2003-12-15')
#
#
	return	dict_aa
#
# -------------------------------------------------------------
sys.stderr.write ("*** 開始 ***\n")
#
dict_aa = data_prepare_proc ()
#
client = pymongo.MongoClient(username='scott',password='tiger123')
db = client['city']
collection = 'aichi'
#
dict_to_mongo_proc (db,collection,dict_aa)
#
sys.stderr.write ("*** 終了 ***\n")
# -------------------------------------------------------------
