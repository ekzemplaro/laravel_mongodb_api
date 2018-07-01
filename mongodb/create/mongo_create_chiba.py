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
	dict_aa = dict_append_proc (dict_aa,'t1271','千葉',59178,'2003-9-21')
	dict_aa = dict_append_proc (dict_aa,'t1272','勝浦',47215,'2003-10-15')
	dict_aa = dict_append_proc (dict_aa,'t1273','市原',83654,'2003-6-14')
	dict_aa = dict_append_proc (dict_aa,'t1274','流山',74251,'2003-9-9')
	dict_aa = dict_append_proc (dict_aa,'t1275','八千代',42516,'2003-8-4')
	dict_aa = dict_append_proc (dict_aa,'t1276','我孫子',35987,'2003-1-21')
	dict_aa = dict_append_proc (dict_aa,'t1277','鴨川',81256,'2003-7-23')
	dict_aa = dict_append_proc (dict_aa,'t1278','銚子',29374,'2003-10-26')
	dict_aa = dict_append_proc (dict_aa,'t1279','市川',87613,'2003-2-17')
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
collection = 'chiba'
#
dict_to_mongo_proc (db,collection,dict_aa)
#
sys.stderr.write ("*** 終了 ***\n")
# -------------------------------------------------------------
